<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Food;
use App\Entity\Household;
use App\Enum\Allergen;
use App\Enum\FoodGroup;
use App\Food\FoodFacts;
use App\Food\RecipeWeigher;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Llm\Prompt;
use App\Message\DescribeFoods;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the text model for the nutrition per 100 g, food groups, allergens, spice and weights of
 * the shared foods nobody has described yet, up to 40 per call. While a call describes some,
 * the next batch is queued; on failure they stay undescribed until new foods come in or
 * `app:foods` is run. Each call is logged in extraction_run (step "foods") for the costs page.
 */
#[AsMessageHandler]
final readonly class DescribeFoodsHandler
{
    private const int BATCH = 40;
    private const string TOOL = 'save_foods';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private MessageBusInterface $bus,
        private RecipeWeigher $weigher,
        private LoggerInterface $logger,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(DescribeFoods $message): void
    {
        $household = $this->entityManager->find(Household::class, Uuid::fromString($message->householdId));
        if (null === $household) {
            return;
        }
        $pending = $this->pending(self::BATCH);
        if ([] === $pending) {
            return;
        }

        $lines = array_map(
            static fn (Food $f): string => \sprintf('- %s (seen as: %s)', $f->getKey(), $f->getNameEs() ?? $f->getNameEn() ?? $f->getKey()),
            $pending,
        );
        $started = hrtime(true);
        try {
            $completion = $this->llm->complete(new Prompt(
                model: $this->model,
                system: self::INSTRUCTIONS,
                content: [Content::text("Describe these foods:\n".implode("\n", $lines))],
                toolName: self::TOOL,
                toolDescription: 'Save the nutrition, groups, allergens, spice and weights of each food.',
                toolSchema: self::schema(),
                maxTokens: 16000,
                thinking: false,
            ));
        } catch (LlmException $e) {
            $this->logger->warning('Food description failed: {error}', ['error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($household, null, 'foods', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->persist(new ExtractionRun(
            $household, null, 'foods', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));

        $byKey = [];
        foreach ($pending as $food) {
            $byKey[$food->getKey()] = $food;
        }
        $described = [];
        $answers = $completion->toolInput['foods'] ?? [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            if (!\is_array($answer) || !\is_string($answer['key'] ?? null) || !isset($byKey[$answer['key']])) {
                continue;
            }
            $byKey[$answer['key']]->describe(self::facts($answer));
            unset($byKey[$answer['key']]);
            $described[] = $answer['key'];
        }
        $this->entityManager->flush();
        // Their weight per piece and density are known now: the recipes using them weighed again.
        $this->weigher->weighRecipesWith($described);
        $this->entityManager->flush();
        $this->logger->info('Described {count} foods ({in} in / {out} out tokens)', [
            'count' => \count($described), 'in' => $completion->inputTokens, 'out' => $completion->outputTokens,
        ]);

        // More waiting: next batch. Not when this one described nothing, or it would loop on the same foods.
        if ([] !== $described && [] !== $this->pending(1)) {
            $this->bus->dispatch(new DescribeFoods($message->householdId));
        }
    }

    /** @return list<Food> */
    private function pending(int $limit): array
    {
        return $this->entityManager->getRepository(Food::class)->findBy(['describedAt' => null], ['key' => 'ASC'], $limit);
    }

    /** @param array<mixed> $answer */
    private static function facts(array $answer): FoodFacts
    {
        $number = static fn (string $field): float => is_numeric($answer[$field] ?? null) ? (float) $answer[$field] : 0.0;
        $optional = static fn (string $field): ?float => is_numeric($answer[$field] ?? null) ? (float) $answer[$field] : null;
        $list = static fn (string $field): array => \is_array($answer[$field] ?? null) ? $answer[$field] : [];

        return new FoodFacts(
            nameEs: \is_string($answer['name_es'] ?? null) ? $answer['name_es'] : null,
            nameEn: \is_string($answer['name_en'] ?? null) ? $answer['name_en'] : null,
            kcal: $number('kcal'),
            protein: $number('protein'),
            fat: $number('fat'),
            saturatedFat: $number('saturated_fat'),
            carbs: $number('carbs'),
            sugar: $number('sugar'),
            fiber: $number('fiber'),
            salt: $number('salt'),
            foodGroups: FoodGroup::listFrom($list('food_groups')),
            allergens: Allergen::listFrom($list('allergens')),
            spice: (int) $number('spice'),
            gramsPerPiece: $optional('grams_per_piece'),
            density: $optional('density'),
        );
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You keep a food composition table for a meal planner used in Spain, with Spanish and English recipes. For each food (a stable English key, and the name a recipe used) give typical values for the food as a recipe uses it: raw unless the key says cooked; tinned or jarred foods drained; dried pasta, rice and pulses dry. Prefer the Spanish food composition database (BEDCA) and USDA FoodData Central figures you know. Round sensibly.
        - name_es and name_en: the everyday name, singular, lowercase, no brand ("cebolla" / "onion").
        - Per 100 g: kcal, protein, fat, saturated_fat, carbs (available carbohydrate, without fibre), sugar, fiber, salt (grams of salt, not sodium). Water and the like: all zero.
        - food_groups: which of the listed groups it belongs to; none for plain vegetables, fruit, grains, pulses, oils, herbs and spices. Meat or poultry stock, gelatin or lard is animal_stock; wheat, barley, rye and regular oats are gluten; butter, cream, milk and yogurt are dairy; cheese is cheese.
        - allergens: the EU allergens it contains or usually contains (wine and vinegar have sulphites; most sausages and stock cubes may have several).
        - spice: 0 not hot, 1 mildly hot (hot paprika, chilli flakes, ginger), 2 hot (fresh chillies, cayenne, hot sauce). Black pepper and sweet or smoked paprika are 0.
        - grams_per_piece: the weight of one piece as counted in a recipe and bought (1 onion ≈ 150, 1 egg ≈ 55 without shell, 1 garlic clove ≈ 5, 1 tin of chickpeas drained ≈ 250, 1 lemon ≈ 120); null for what is never counted (flour, oil, minced meat).
        - density: grams per millilitre, for what can be measured with spoons or cups (water 1, oil 0.92, flour 0.55, sugar 0.85, salt 1.2, honey 1.4); null for what never is (a chicken breast).
        Answer by calling the save_foods tool once, with every food.
        TXT;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $number = ['type' => 'number'];
        $nullableNumber = ['type' => ['number', 'null']];

        // Ready for strict tool use (Claude): every object closed, every property required.
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['foods'],
            'properties' => [
                'foods' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'name_es', 'name_en', 'kcal', 'protein', 'fat', 'saturated_fat', 'carbs', 'sugar', 'fiber', 'salt', 'food_groups', 'allergens', 'spice', 'grams_per_piece', 'density'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name_es' => ['type' => 'string'],
                            'name_en' => ['type' => 'string'],
                            'kcal' => $number,
                            'protein' => $number,
                            'fat' => $number,
                            'saturated_fat' => $number,
                            'carbs' => $number,
                            'sugar' => $number,
                            'fiber' => $number,
                            'salt' => $number,
                            'food_groups' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => FoodGroup::values()]],
                            'allergens' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Allergen::values()]],
                            'spice' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                            'grams_per_piece' => $nullableNumber,
                            'density' => $nullableNumber,
                        ],
                    ],
                ],
            ],
        ];
    }
}
