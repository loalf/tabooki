<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Food;
use App\Entity\FoodSubstitution;
use App\Entity\Household;
use App\Entity\Ingredient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What can stand in for a household's ingredients: the substitutes of each one's food (the one
 * the household pointed it at, else the catalog's with its key).
 *
 * @phpstan-type Substitute array{key: string, name: array{es: string, en: string}, ratio: float, note: array{es: string, en: string}|null}
 */
final readonly class Substitutes
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $keys ingredient keys
     *
     * @return array<string, list<Substitute>> by ingredient key, best first; keys without any left out
     */
    public function forKeys(Household $household, array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $k): bool => '' !== $k)));
        if ([] === $keys) {
            return [];
        }
        // Which food each key stands for.
        $foodOf = [];
        foreach ($this->entityManager->getRepository(Food::class)->findBy(['key' => $keys]) as $food) {
            $foodOf[$food->getKey()] = $food->getId()->toRfc4122();
        }
        /** @var list<Ingredient> $ingredients */
        $ingredients = $this->entityManager->getRepository(Ingredient::class)->findBy(['household' => $household, 'canonicalKey' => $keys]);
        foreach ($ingredients as $ingredient) {
            if (null !== $ingredient->getFood()) {
                $foodOf[$ingredient->getCanonicalKey()] = $ingredient->getFood()->getId()->toRfc4122();
            }
        }
        if ([] === $foodOf) {
            return [];
        }

        /** @var list<FoodSubstitution> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s', 'f')->from(FoodSubstitution::class, 's')->join('s.food', 'f')
            ->where('f.id IN (:foods)')->setParameter('foods', array_values(array_unique($foodOf)))
            ->orderBy('s.position')
            ->getQuery()->getResult();
        $byFood = [];
        foreach ($rows as $row) {
            $byFood[$row->getFood()->getId()->toRfc4122()][] = $row->toArray();
        }

        $substitutes = [];
        foreach ($foodOf as $key => $food) {
            if (isset($byFood[$food])) {
                $substitutes[$key] = $byFood[$food];
            }
        }

        return $substitutes;
    }
}
