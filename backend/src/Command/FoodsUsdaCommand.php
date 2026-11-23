<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Food;
use App\Entity\Household;
use App\Enum\FoodSource;
use App\Food\SavedPairs;
use App\Food\TableEntry;
use App\Food\TableImport;
use App\Food\Usda\UsdaClient;
use App\Food\Usda\UsdaFigures;
use App\Food\Usda\UsdaMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fills in from USDA FoodData Central what BEDCA lacks (docs/INGREDIENT-KNOWLEDGE.md): foods still
 * estimated by the model, not those from BEDCA or fixed by hand. Each is searched by its key in
 * words and the text model picks the result that is it; pairs are kept under var/usda (--pair
 * key=fdcId sets one by hand). Shown, then imported once confirmed, like app:foods:bedca.
 */
#[AsCommand(name: 'app:foods:usda', description: 'Import USDA FoodData Central\'s figures for foods BEDCA lacks')]
final readonly class FoodsUsdaCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UsdaClient $usda,
        private UsdaMatcher $matcher,
        private TableImport $import,
        #[Autowire('%app.usda_dir%')]
        private string $dir,
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
        #[Option(description: 'Also foods already imported from USDA')] bool $refresh = false,
        #[Option(description: 'Leave these food keys out (a pairing that looked wrong)')] array $skip = [],
        #[Option(description: 'Pair a food with a USDA FDC id by hand, key=id (0: none); kept for next time')] array $pair = [],
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
                && FoodSource::Bedca !== $f->getSource()
                && ($refresh || FoodSource::Usda !== $f->getSource())
                && ([] === $keys || \in_array($f->getKey(), $keys, true))
                && !\in_array($f->getKey(), $skip, true),
        ));
        if ([] === $foods) {
            $io->success('No food to import.');

            return Command::SUCCESS;
        }

        $byHand = SavedPairs::parse($pair);
        if (\is_string($byHand)) {
            $io->error($byHand);

            return Command::INVALID;
        }
        $pairs = new SavedPairs($this->dir.'/pairs.json');
        $pairs->save($byHand);
        $saved = $rematch ? $byHand : $pairs->all();
        $unpaired = array_values(array_filter($foods, static fn (Food $f): bool => !\array_key_exists($f->getKey(), $saved)));
        if ([] !== $unpaired) {
            $io->text(\sprintf('Searching USDA for %d food(s) and pairing them…', \count($unpaired)));
            $proposed = $this->matcher->match($unpaired, $payer);
            $new = [];
            foreach ($unpaired as $food) {
                $new[$food->getKey()] = ($proposed[$food->getKey()] ?? null)?->fdcId;
            }
            $pairs->save($new);
            $saved = [...$saved, ...$new];
        }

        $entries = [];
        foreach ($foods as $food) {
            $id = $saved[$food->getKey()] ?? null;
            $usdaFood = null === $id ? null : $this->usda->food($id);
            $entries[$food->getKey()] = null === $usdaFood ? null : UsdaFigures::entry($usdaFood);
        }

        /* @var array<string, TableEntry|null> $entries */
        return $this->import->run($io, FoodSource::Usda, 'app:foods:usda', $foods, $entries, $keys, $apply);
    }
}
