<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\EmbedRecipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues every recipe to be embedded for search by meaning: after the feature is deployed, or the
 * embedding model changed. Recipes whose digest and model are unchanged are skipped by the worker.
 */
#[AsCommand(name: 'app:embed', description: 'Queue every recipe for search by meaning (unchanged ones cost nothing)')]
final readonly class EmbedCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $ids = array_filter($this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM recipe ORDER BY created_at'), \is_string(...));
        foreach ($ids as $id) {
            $this->bus->dispatch(new EmbedRecipe($id));
        }
        $io->success(\sprintf('%d recipe(s) queued.', \count($ids)));

        return Command::SUCCESS;
    }
}
