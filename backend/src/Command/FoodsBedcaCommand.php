<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Food;
use App\Entity\Household;
use App\Enum\FoodSource;
use App\Food\Bedca\BedcaClient;
use App\Food\Bedca\BedcaFigures;
use App\Food\Bedca\BedcaMatcher;
use App\Food\SavedPairs;
use App\Food\TableImport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Replaces the model's estimates with BEDCA's figures (docs/INGREDIENT-KNOWLEDGE.md, "Data
 * licences"): the text model pairs each food with a BEDCA food (once: the pairs are kept under
 * var/tables/bedca, and --pair fixes one by hand), the pairs and the figures are shown, and imported once
 * confirmed (or with --apply). Not foods fixed by hand; not foods already
 * from BEDCA unless --refresh. A BEDCA entry whose energy contradicts its own nutrients is left
 * out. What BEDCA doesn't give (often sugars) stays the estimate, and says so.
 */
#[AsCommand(name: 'app:foods:bedca', description: 'Import BEDCA\'s figures (Spanish food composition database) over the model\'s estimates')]
final readonly class FoodsBedcaCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BedcaClient $bedca,
        private BedcaMatcher $matcher,
        private TableImport $import,
        #[Autowire('%app.bedca_dir%')]
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
        $byHand = SavedPairs::parse($pair);
        if (\is_string($byHand) || [] !== array_filter($byHand, static fn (?int $id): bool => null !== $id && !isset($byId[$id]))) {
            $io->error(\is_string($byHand) ? $byHand : 'Unknown BEDCA id in --pair.');

            return Command::INVALID;
        }
        $pairs = new SavedPairs($this->dir.'/pairs.json');
        $pairs->save($byHand);
        $saved = $rematch ? $byHand : $pairs->all();
        $unpaired = array_values(array_filter($foods, static fn (Food $f): bool => !\array_key_exists($f->getKey(), $saved)));
        if ([] !== $unpaired) {
            $io->text(\sprintf('Pairing %d food(s) with BEDCA…', \count($unpaired)));
            $proposed = $this->matcher->match($unpaired, $bedcaFoods, $payer);
            $new = [];
            foreach ($unpaired as $food) {
                $new[$food->getKey()] = ($proposed[$food->getKey()] ?? null)?->id;
            }
            $pairs->save($new);
            $saved = [...$saved, ...$new];
        }

        $entries = [];
        foreach ($foods as $food) {
            $id = $saved[$food->getKey()] ?? null;
            $entries[$food->getKey()] = null === $id || !isset($byId[$id]) ? null : BedcaFigures::entry($byId[$id], $this->bedca->components($id));
        }

        return $this->import->run($io, FoodSource::Bedca, 'app:foods:bedca', $foods, $entries, $keys, $apply);
    }
}
