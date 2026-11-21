<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recipe;
use App\Enum\SourceStatus;
use App\Extraction\RecipeExtractor;
use App\Message\ExtractSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After switching to a newer model: queue every recipe extracted with another model to be
 * extracted again (in place: ratings, favorites and planned meals stay). Recipes the cook has
 * reviewed are left alone unless --reviewed, since re-extracting replaces their edits.
 */
#[AsCommand(name: 'app:reextract', description: 'Extract again the recipes made with an older model')]
final readonly class ReextractCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecipeExtractor $extractor,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only recipes extracted with this model (default: any model but the current ones)')] ?string $model = null,
        #[Option(description: 'Also recipes already reviewed (their edits are replaced)')] bool $reviewed = false,
        #[Option(description: 'List them without queueing anything')] bool $dryRun = false,
    ): int {
        $current = [$this->extractor->textModel(), $this->extractor->videoModel()];
        $query = $this->entityManager->createQueryBuilder()
            ->select('r', 's')->from(Recipe::class, 'r')->join('r.source', 's')
            ->where('r.extractedBy IS NOT NULL')
            ->orderBy('r.createdAt', 'ASC');
        null !== $model
            ? $query->andWhere('r.extractedBy = :model')->setParameter('model', $model)
            : $query->andWhere('r.extractedBy NOT IN (:current)')->setParameter('current', $current);
        if (!$reviewed) {
            $query->andWhere('r.needsReview = true');
        }
        /** @var list<Recipe> $recipes */
        $recipes = $query->getQuery()->getResult();

        if ([] === $recipes) {
            $io->success('Nothing to re-extract.'.($reviewed ? '' : ' (Reviewed recipes are skipped; add --reviewed to include them.)'));

            return Command::SUCCESS;
        }

        $queued = 0;
        $mine = [];
        foreach ($recipes as $recipe) {
            $source = $recipe->getSource();
            // Skip links already being extracted, unless by this run (a link with several recipes).
            if (null === $source || (!isset($mine[$source->getId()->toRfc4122()]) && \in_array($source->getStatus(), [SourceStatus::Pending, SourceStatus::Extracting], true))) {
                continue;
            }
            $mine[$source->getId()->toRfc4122()] = true;
            $io->writeln(\sprintf('%s  <comment>%s</comment>  %s', $dryRun ? 'Would queue' : 'Queued', $recipe->getExtractedBy(), $recipe->getTitle()));
            if (!$dryRun) {
                $source->setStatus(SourceStatus::Pending);
                $this->entityManager->flush();
                $this->bus->dispatch(new ExtractSource($source->getId()->toRfc4122(), $recipe->getId()->toRfc4122()));
            }
            ++$queued;
        }
        $io->success(\sprintf('%d recipe(s) %s with %s.', $queued, $dryRun ? 'would be re-extracted' : 'queued', implode(' / ', $current)));

        return Command::SUCCESS;
    }
}
