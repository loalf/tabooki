<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Food;
use App\Entity\Household;
use App\Entity\Ingredient;
use App\Enum\FoodSource;
use App\Food\Bedca\BedcaClient;
use App\Food\Bedca\BedcaFigures;
use App\Food\Bedca\BedcaFood;
use App\Food\Bedca\BedcaMatcher;
use App\Food\Bedca\BedcaPairs;
use App\Food\FoodFacts;
use App\Food\RecipeWeigher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replaces the model's estimates with BEDCA's figures (docs/INGREDIENT-KNOWLEDGE.md, "Data
 * licences"): the text model pairs each food with a BEDCA food (once: the pairs are kept under
 * var/bedca, and --pair fixes one by hand), the pairs and the figures are shown, and imported once
 * confirmed (or with --apply). Not foods fixed by hand; not foods already
 * from BEDCA unless --refresh. A BEDCA entry whose energy contradicts its own nutrients is left
 * out. What BEDCA doesn't give (often sugars) stays the estimate, and says so.
 */
#[AsCommand(name: 'app:foods:bedca', description: 'Import BEDCA\'s figures (Spanish food composition database) over the model\'s estimates')]
final readonly class FoodsBedcaCommand
{
    private const array NUTRIENTS = ['kcal', 'protein', 'fat', 'saturatedFat', 'carbs', 'sugar', 'fiber', 'salt'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BedcaClient $bedca,
        private BedcaMatcher $matcher,
        private BedcaPairs $pairs,
        private RecipeWeigher $weigher,
    ) {
    }

    /**
     * @param list<string> $keys
     * @param list<string> $skip
     * @param list<string> $pair
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Only these food keys (all when left out); named, a food far from its estimate is imported too')] array $keys = [],
        #[Option(description: 'Import without asking')] bool $apply = false,
        #[Option(description: 'Also foods already imported from BEDCA')] bool $refresh = false,
        #[Option(description: 'Leave these food keys out (a pairing that looked wrong)')] array $skip = [],
        #[Option(description: 'Pair a food with a BEDCA id by hand, key=id (0: none); kept for next time')] array $pair = [],
        #[Option(description: 'Have the model pair the foods again, not reuse the saved pairs')] bool $rematch = false,
    ): int {
        $payer = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        if (null === $payer) {
            $io->success('No household, no catalog.');

            return Command::SUCCESS;
        }
        $foods = array_values(array_filter(
            $this->entityManager->getRepository(Food::class)->findBy([], ['key' => 'ASC']),
            static fn (Food $f): bool => $f->isDescribed() && !$f->isEdited()
                && ($refresh || FoodSource::Bedca !== $f->getSource())
                && ([] === $keys || \in_array($f->getKey(), $keys, true))
                && !\in_array($f->getKey(), $skip, true),
        ));
        if ([] === $foods) {
            $io->success('No food to import.');

            return Command::SUCCESS;
        }

        // Pairs: set by hand, else saved from an earlier run, else proposed by the model now (and saved).
        $bedcaFoods = $this->bedca->foods();
        $byId = [];
        foreach ($bedcaFoods as $bedcaFood) {
            $byId[$bedcaFood->id] = $bedcaFood;
        }
        $byHand = [];
        foreach ($pair as $given) {
            [$key, $id] = array_pad(explode('=', $given, 2), 2, '');
            if ('' === $key || !ctype_digit($id) || ('0' !== $id && !isset($byId[(int) $id]))) {
                $io->error(\sprintf('"%s": give key=id, with a BEDCA id (0 for none).', $given));

                return Command::INVALID;
            }
            $byHand[$key] = '0' === $id ? null : (int) $id;
        }
        $this->pairs->save($byHand);
        $saved = $rematch ? $byHand : $this->pairs->all();
        $unpaired = array_values(array_filter($foods, static fn (Food $f): bool => !\array_key_exists($f->getKey(), $saved)));
        if ([] !== $unpaired) {
            $io->text(\sprintf('Pairing %d food(s) with BEDCA…', \count($unpaired)));
            $proposed = $this->matcher->match($unpaired, $bedcaFoods, $payer);
            $new = [];
            foreach ($unpaired as $food) {
                $new[$food->getKey()] = ($proposed[$food->getKey()] ?? null)?->id;
            }
            $this->pairs->save($new);
            $saved = [...$saved, ...$new];
        }
        $matches = [];
        foreach ($saved as $key => $id) {
            if (null !== $id && isset($byId[$id])) {
                $matches[$key] = $byId[$id];
            }
        }

        $rows = [];
        $imports = [];
        foreach ($foods as $food) {
            $match = $matches[$food->getKey()] ?? null;
            if (null === $match) {
                $rows[] = [$food->getKey(), '–', self::kcal($food->getKcal()), '', 'no BEDCA food'];
                continue;
            }
            $figures = BedcaFigures::of($this->bedca->components($match->id));
            $missing = array_values(array_filter(self::NUTRIENTS, static fn (string $n): bool => null === $figures->values[$n]));
            $rows[] = [
                $food->getKey(),
                $match->id.' '.$match->nameEs,
                self::kcal($food->getKcal()),
                self::kcal($figures->values['kcal']),
                $figures->problem ?? ([] === $missing ? '' : 'estimated: '.implode(', ', $missing)),
            ];
            // Far from the estimate: a wrong pairing, or a bad estimate. Imported only when named.
            $now = $food->getKcal();
            $then = $figures->values['kcal'];
            $far = null !== $now && null !== $then && abs($now - $then) > 40 && max($now, $then) > 1.5 * max(1.0, min($now, $then));
            if ($far && null === $figures->problem && !\in_array($food->getKey(), $keys, true)) {
                $rows[\count($rows) - 1][4] = 'far from the estimate: check, then app:foods:bedca '.$food->getKey();
                continue;
            }
            if (null === $figures->problem) {
                $imports[] = [$food, $match, $figures, $missing];
            }
        }
        $io->table(['Food', 'BEDCA', 'kcal now', 'kcal BEDCA', 'Note'], $rows);

        if ([] === $imports) {
            $io->success('Nothing to import.');

            return Command::SUCCESS;
        }
        if (!$apply && !$io->confirm(\sprintf('Import BEDCA\'s figures for %d food(s)?', \count($imports)), false)) {
            $io->text('Nothing imported.');

            return Command::SUCCESS;
        }

        $changed = [];
        foreach ($imports as [$food, $match, $figures, $missing]) {
            /** @var Food $food */
            /** @var BedcaFood $match */
            /** @var BedcaFigures $figures */
            $value = static fn (string $field, ?float $current): float => $figures->values[$field] ?? $current ?? 0.0;
            $food->adopt(new FoodFacts(
                nameEs: null,
                nameEn: null,
                kcal: $value('kcal', $food->getKcal()),
                protein: $value('protein', $food->getProtein()),
                fat: $value('fat', $food->getFat()),
                saturatedFat: $value('saturatedFat', $food->getSaturatedFat()),
                carbs: $value('carbs', $food->getCarbs()),
                sugar: $value('sugar', $food->getSugar()),
                fiber: $value('fiber', $food->getFiber()),
                salt: $value('salt', $food->getSalt()),
                foodGroups: $food->getFoodGroups(),
                allergens: $food->getAllergens(),
                spice: $food->getSpice() ?? 0,
                gramsPerPiece: $food->getGramsPerPiece(),
                density: $food->getDensity(),
            ), FoodSource::Bedca, $match->id.' '.$match->nameEs, $missing);
            $changed[] = $food;
        }
        $this->entityManager->flush();

        // Their recipes, by key or through an ingredient pointed at them, worked out again.
        $recipeKeys = array_map(static fn (Food $f): string => $f->getKey(), $changed);
        foreach ($this->entityManager->getRepository(Ingredient::class)->findBy(['food' => $changed]) as $ingredient) {
            $recipeKeys[] = $ingredient->getCanonicalKey();
        }
        $recipes = $this->weigher->weighRecipesWith(array_values(array_unique($recipeKeys)));
        $this->entityManager->flush();

        $io->success(\sprintf('%d food(s) now from BEDCA; %d recipe(s) worked out again.', \count($changed), $recipes));

        return Command::SUCCESS;
    }

    private static function kcal(?float $kcal): string
    {
        return null === $kcal ? '?' : (string) (int) round($kcal);
    }
}
