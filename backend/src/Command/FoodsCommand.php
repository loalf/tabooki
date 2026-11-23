<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Food;
use App\Entity\Household;
use App\Entity\Recipe;
use App\Food\FoodCatalog;
use App\Message\DescribeFoods;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Brings every ingredient of every recipe into the shared food catalog, points the households'
 * catalog ingredients that don't point anywhere yet at the food with their key, and queues the
 * description of the foods that lack one: recipes saved before the catalog existed, or a
 * description that failed. New and edited recipes do this on their own.
 */
#[AsCommand(name: 'app:foods', description: 'Fill the shared food catalog from the recipes and describe what is missing')]
final readonly class FoodsCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FoodCatalog $foods,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $added = 0;
        $households = $this->entityManager->getRepository(Household::class)->findAll();
        foreach ($households as $household) {
            /** @var list<Recipe> $recipes */
            $recipes = $this->entityManager->createQueryBuilder()
                ->select('r', 'i')->from(Recipe::class, 'r')->leftJoin('r.ingredients', 'i')
                ->where('r.household = :household')->setParameter('household', $household)
                ->getQuery()->getResult();
            foreach ($recipes as $recipe) {
                $added += $this->foods->recipeChanged($recipe);
            }
        }

        $linked = $this->entityManager->getConnection()->executeStatement(
            'UPDATE ingredient i SET food_id = f.id FROM food f WHERE i.food_id IS NULL AND f.key = i.canonical_key',
        );

        $undescribed = $this->entityManager->getRepository(Food::class)->count(['describedAt' => null]);
        if ($undescribed > 0 && 0 === $added && [] !== $households) {
            // Nothing new, so nothing was queued: queue it now (describing chains batch after batch).
            $this->bus->dispatch(new DescribeFoods($households[0]->getId()->toRfc4122()));
        }

        $io->success(\sprintf(
            '%d new food(s), %d catalog ingredient(s) linked, %d food(s) %s.',
            $added, $linked, $undescribed, $undescribed > 0 ? 'queued to be described' : 'left to describe',
        ));

        return Command::SUCCESS;
    }
}
