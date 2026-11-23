<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Food;
use App\Library\SearchText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Foods whose key or names have every word searched for, those most used in recipes first: what
 * an ingredient can be pointed at. In PHP, accent-insensitive like the library search: the shared
 * catalog holds a few hundred foods.
 *
 * @implements ProviderInterface<Food>
 */
final readonly class FoodSearchProvider implements ProviderInterface
{
    private const int LIMIT = 30;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<Food> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $query = $operation->getParameters()?->get('q')?->getValue();
        $words = \is_string($query) ? \array_slice(SearchText::words($query), 0, 6) : [];

        $use = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative('SELECT canonical_key, COUNT(*) AS n FROM recipe_ingredient GROUP BY canonical_key') as $row) {
            if (\is_string($row['canonical_key'] ?? null) && is_numeric($row['n'] ?? null)) {
                $use[$row['canonical_key']] = (int) $row['n'];
            }
        }

        $found = [];
        foreach ($this->entityManager->getRepository(Food::class)->findAll() as $food) {
            $text = SearchText::normalize(implode(' ', [str_replace('_', ' ', $food->getKey()), $food->getNameEs() ?? '', $food->getNameEn() ?? '']));
            if ([] === array_filter($words, static fn (string $w): bool => !str_contains($text, $w))) {
                $found[] = $food;
            }
        }
        usort($found, static fn (Food $a, Food $b): int => ($use[$b->getKey()] ?? 0) <=> ($use[$a->getKey()] ?? 0) ?: strcmp($a->getKey(), $b->getKey()));

        return \array_slice($found, 0, self::LIMIT);
    }
}
