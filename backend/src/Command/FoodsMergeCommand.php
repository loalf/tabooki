<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Food\KeyMerger;
use App\Food\KeyReview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Merges ingredient keys that are the same ingredient: "ajo" into "garlic", "cherry_tomatoes"
 * into "cherry_tomato". With two keys, merges that pair. Without, the text model reviews the
 * whole food catalog and proposes merges, shown first and made once confirmed (or with --apply).
 */
#[AsCommand(name: 'app:foods:merge', description: 'Merge ingredient keys that are the same ingredient (Spanish, plural, reworded)')]
final readonly class FoodsMergeCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private KeyReview $review,
        private KeyMerger $merger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The key to merge away ("ajo"); leave both out to have the catalog reviewed')] ?string $from = null,
        #[Argument(description: 'The key it becomes ("garlic")')] ?string $to = null,
        #[Option(description: 'Make the proposed merges without asking')] bool $apply = false,
    ): int {
        if (null !== $from) {
            if (null === $to) {
                $io->error('Give the key it becomes too: app:foods:merge ajo garlic');

                return Command::INVALID;
            }

            return $this->run($io, [['from' => $from, 'to' => $to]]);
        }

        $payer = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        if (null === $payer) {
            $io->success('No household, no catalog.');

            return Command::SUCCESS;
        }
        $merges = $this->review->propose($payer);
        if ([] === $merges) {
            $io->success('Nothing to merge.');

            return Command::SUCCESS;
        }
        $io->table(['From', 'To'], array_map(static fn (array $m): array => [$m['from'], $m['to']], $merges));
        // Asked here, so what is merged is what was shown (another review could propose something else).
        if (!$apply && !$io->confirm(\sprintf('Merge these %d?', \count($merges)), false)) {
            $io->note(\sprintf('%d merge(s) proposed, none made. Answer yes, use --apply, or merge pairs one by one: app:foods:merge <from> <to>', \count($merges)));

            return Command::SUCCESS;
        }

        return $this->run($io, $merges);
    }

    /** @param list<array{from: string, to: string}> $merges */
    private function run(SymfonyStyle $io, array $merges): int
    {
        foreach ($merges as $merge) {
            $changed = $this->merger->merge($merge['from'], $merge['to']);
            $io->writeln(\sprintf(
                '%s → %s: %d recipe line(s), %d catalog ingredient(s), %d shopping list(s), %d taste profile(s)',
                $merge['from'], $merge['to'], $changed['recipeLines'], $changed['ingredients'], $changed['lists'], $changed['tastes'],
            ));
        }
        $io->success(\sprintf('%d key(s) merged. Run app:foods to describe any key that is new.', \count($merges)));

        return Command::SUCCESS;
    }
}
