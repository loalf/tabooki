<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Entity\Household;
use App\Entity\Ingredient;
use App\Food\FoodCatalog;
use App\Message\ClassifyIngredients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Finds, or adds, the household's catalog entries for a set of ingredient keys. */
final readonly class IngredientCatalog
{
    /** Known pantry staples, until (or unless) the text model says otherwise. */
    private const array STAPLES = [
        'salt', 'sea_salt', 'pepper', 'black_pepper', 'white_pepper', 'water', 'olive_oil', 'extra_virgin_olive_oil', 'oil',
        'vegetable_oil', 'sunflower_oil', 'sugar', 'flour', 'wheat_flour', 'vinegar', 'paprika', 'sweet_paprika',
        'smoked_paprika', 'cumin', 'oregano', 'bay_leaf', 'thyme', 'cinnamon', 'nutmeg', 'baking_powder',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private FoodCatalog $foods,
    ) {
    }

    /**
     * @param array<string, array{name: string, language: string}> $seen a name for each key, as recipes have it
     *
     * @return array<string, Ingredient> by key
     */
    public function forKeys(Household $household, array $seen): array
    {
        if ([] === $seen) {
            return [];
        }

        // Each new ingredient points at the shared food with its key, so that food must exist first.
        $this->foods->ensure($household, $seen);

        // Insert the missing ones in one statement; ON CONFLICT makes it safe for concurrent requests.
        $connection = $this->entityManager->getConnection();
        $values = [];
        $parameters = ['household' => $household->getId()->toRfc4122()];
        $i = 0;
        foreach ($seen as $key => $name) {
            $values[] = \sprintf('(:id%1$d, :household, :key%1$d, :es%1$d, :en%1$d, \'other\', :staple%1$d, false, (SELECT id FROM food WHERE key = :key%1$d))', $i);
            $parameters['id'.$i] = Uuid::v7()->toRfc4122();
            $parameters['key'.$i] = $key;
            $parameters['es'.$i] = 'es' === $name['language'] ? mb_substr($name['name'], 0, 100) : null;
            $parameters['en'.$i] = 'en' === $name['language'] ? mb_substr($name['name'], 0, 100) : null;
            $parameters['staple'.$i] = \in_array($key, self::STAPLES, true) ? 'true' : 'false';
            ++$i;
        }
        $inserted = $connection->executeStatement(
            'INSERT INTO ingredient (id, household_id, canonical_key, name_es, name_en, aisle, staple, edited, food_id) VALUES '
            .implode(', ', $values).' ON CONFLICT (household_id, canonical_key) DO NOTHING',
            $parameters,
        );
        if ($inserted > 0) {
            $this->bus->dispatch(new ClassifyIngredients($household->getId()->toRfc4122()));
        }

        /** @var list<Ingredient> $ingredients */
        $ingredients = $this->entityManager->getRepository(Ingredient::class)->findBy(['household' => $household, 'canonicalKey' => array_keys($seen)]);
        $byKey = [];
        foreach ($ingredients as $ingredient) {
            $byKey[$ingredient->getCanonicalKey()] = $ingredient;
        }

        return $byKey;
    }

    /** A key for ingredients without one (typed in by hand): the name, simplified. */
    public static function keyFor(?string $canonicalKey, ?string $name, string $rawText): string
    {
        if (null !== $canonicalKey && '' !== $canonicalKey) {
            return $canonicalKey;
        }
        $text = mb_strtolower($name ?? $rawText);
        $ascii = (string) (\Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($text) ?? $text);

        return 'x_'.trim((string) preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
    }
}
