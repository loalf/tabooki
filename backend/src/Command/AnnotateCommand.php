<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recipe;
use App\Enum\SourceStatus;
use App\Message\AnnotateRecipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues every recipe whose facts (heaviness, spice, food groups) haven't been read yet, or
 * were read from an older text, to be read by the worker: recipes saved before facts existed,
 * or whose reading failed. New and edited recipes are read on their own.
 */
#[AsCommand(name: 'app:annotate', description: 'Read the facts (heaviness, spice, food groups) of the recipes that lack them')]
final readonly class AnnotateCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'List them without queueing anything')] bool $dryRun = false,
    ): int {
        /** @var list<Recipe> $recipes */
        $recipes = $this->entityManager->createQueryBuilder()
            ->select('r', 'i')->from(Recipe::class, 'r')->leftJoin('r.ingredients', 'i')
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()->getResult();

        $queued = 0;
        foreach ($recipes as $recipe) {
            // Being extracted: the extraction queues the reading when it's done.
            $status = $recipe->getSource()?->getStatus();
            if (!$recipe->needsFacts() || \in_array($status, [SourceStatus::Pending, SourceStatus::Extracting], true)) {
                continue;
            }
            $io->writeln(\sprintf('%s  %s', $dryRun ? 'Would queue' : 'Queued', $recipe->getTitle()));
            if (!$dryRun) {
                $this->bus->dispatch(new AnnotateRecipe($recipe->getId()->toRfc4122()));
            }
            ++$queued;
        }
        $io->success(0 === $queued ? 'Every recipe has its facts.' : \sprintf('%d recipe(s) %s.', $queued, $dryRun ? 'would be read' : 'queued'));

        return Command::SUCCESS;
    }
}
