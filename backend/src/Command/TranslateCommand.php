<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recipe;
use App\Enum\SourceStatus;
use App\Message\TranslateRecipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues every recipe that isn't in both languages yet to be translated by the worker: recipes
 * saved before translations existed, or whose translation failed. New and edited recipes are
 * translated on their own.
 */
#[AsCommand(name: 'app:translate', description: 'Translate the recipes not yet written in both languages')]
final readonly class TranslateCommand
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
            // Being extracted: the extraction queues its translation when it's done.
            $status = $recipe->getSource()?->getStatus();
            if (!$recipe->needsTranslation() || \in_array($status, [SourceStatus::Pending, SourceStatus::Extracting], true)) {
                continue;
            }
            $io->writeln(\sprintf('%s  <comment>%s → %s</comment>  %s', $dryRun ? 'Would queue' : 'Queued', $recipe->getLanguage()->value, $recipe->getLanguage()->other()->value, $recipe->getTitle()));
            if (!$dryRun) {
                $this->bus->dispatch(new TranslateRecipe($recipe->getId()->toRfc4122()));
            }
            ++$queued;
        }
        $io->success(0 === $queued ? 'Every recipe is in both languages.' : \sprintf('%d recipe(s) %s.', $queued, $dryRun ? 'would be translated' : 'queued for translation'));

        return Command::SUCCESS;
    }
}
