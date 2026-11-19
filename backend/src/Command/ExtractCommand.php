<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Source;
use App\Enum\SourceStatus;
use App\Message\ExtractSource;
use App\MessageHandler\ExtractSourceHandler;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Queue (or run right here) the extraction of saved links: links saved before extraction
 * existed, failed ones after fixing the setup, or one link while debugging.
 */
#[AsCommand(name: 'app:extract', description: 'Extract recipes from saved links')]
final readonly class ExtractCommand
{
    public function __construct(
        private SourceRepository $sources,
        private MessageBusInterface $bus,
        private ExtractSourceHandler $handler,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param list<string> $ids */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Source ids (see the URL of "Write recipe", or the source table)')] array $ids = [],
        #[Option(description: 'Every link without a recipe that is pending, failed or waiting for input')] bool $all = false,
        #[Option(description: 'Run now and show the result, instead of queueing for the worker')] bool $sync = false,
    ): int {
        $sources = $all ? $this->withoutRecipe() : array_filter(array_map(
            fn (string $id): ?Source => Uuid::isValid($id) ? $this->sources->find(Uuid::fromString($id)) : null,
            $ids,
        ));
        if ([] === $sources) {
            $io->warning('No links to extract. Pass source ids, or --all.');

            return Command::INVALID;
        }

        foreach ($sources as $source) {
            $id = $source->getId()->toRfc4122();
            if (!$sync) {
                $this->bus->dispatch(new ExtractSource($id));
                $io->writeln('Queued '.$source->getUrl());
                continue;
            }

            $io->section($source->getUrl());
            ($this->handler)(new ExtractSource($id));
            $this->entityManager->refresh($source);
            $io->writeln(\sprintf('Status: <info>%s</info>%s', $source->getStatus()->value, null !== $source->getStatusMessage() ? ' - '.$source->getStatusMessage() : ''));
            foreach ($source->getRecipes() as $recipe) {
                $io->writeln(\sprintf(
                    '  • %s (%d ingredients, %d steps, confidence %.2f%s)',
                    $recipe->getTitle(), \count($recipe->getIngredients()), \count($recipe->getSteps()),
                    $recipe->getConfidence() ?? 0, $recipe->getNeedsReview() ? ', needs review' : '',
                ));
            }
        }

        return Command::SUCCESS;
    }

    /** @return list<Source> */
    private function withoutRecipe(): array
    {
        return array_values(array_filter(
            $this->sources->findBy(['status' => [SourceStatus::Pending, SourceStatus::Failed, SourceStatus::NeedsInput]]),
            static fn (Source $source): bool => $source->getRecipes()->isEmpty(),
        ));
    }
}
