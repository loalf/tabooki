<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\Household;
use App\Entity\Recipe;
use App\Message\DescribeFoods;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Adds the shared foods for ingredient keys nobody has brought in before, and has them described. */
final readonly class FoodCatalog
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * After a recipe was added or changed: a food for each of its ingredients.
     *
     * @return int how many foods are new
     */
    public function recipeChanged(Recipe $recipe): int
    {
        $seen = [];
        foreach ($recipe->getIngredients() as $ingredient) {
            $key = $ingredient->getCanonicalKey();
            if (null !== $key && '' !== $key) {
                $seen[$key] ??= ['name' => $ingredient->getName() ?? $ingredient->getRawText(), 'language' => $recipe->getLanguage()->value];
            }
        }

        return $this->ensure($recipe->getHousehold(), $seen);
    }

    /**
     * @param array<string, array{name: string, language: string}> $seen a name for each key, as recipes have it
     *
     * @return int how many foods are new
     */
    public function ensure(Household $household, array $seen): int
    {
        // Only the keys extraction gives ("onion"), not those made up from a line typed by hand
        // ("x_1_kg_de_patatas"): those belong to one household, and say little about the food.
        $seen = array_filter($seen, static fn (string|int $key): bool => '' !== (string) $key && !str_starts_with((string) $key, 'x_'), \ARRAY_FILTER_USE_KEY);
        if ([] === $seen) {
            return 0;
        }

        // One statement; ON CONFLICT makes it safe for concurrent workers and requests.
        $values = [];
        $parameters = [];
        $i = 0;
        foreach ($seen as $key => $name) {
            $values[] = \sprintf('(:id%1$d, :key%1$d, :es%1$d, :en%1$d, \'[]\', \'[]\', false)', $i);
            $parameters['id'.$i] = Uuid::v7()->toRfc4122();
            $parameters['key'.$i] = mb_substr((string) $key, 0, 80);
            $parameters['es'.$i] = 'es' === $name['language'] ? mb_substr($name['name'], 0, 100) : null;
            $parameters['en'.$i] = 'en' === $name['language'] ? mb_substr($name['name'], 0, 100) : null;
            ++$i;
        }
        $inserted = (int) $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO food (id, key, name_es, name_en, food_groups, allergens, edited) VALUES '
            .implode(', ', $values).' ON CONFLICT (key) DO NOTHING',
            $parameters,
        );
        if ($inserted > 0) {
            $this->bus->dispatch(new DescribeFoods($household->getId()->toRfc4122()));
        }

        return $inserted;
    }
}
