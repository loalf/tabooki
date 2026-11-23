<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExtractionRun;
use App\Entity\Food;
use App\Entity\FoodSubstitution;
use App\Entity\Household;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Llm\Prompt;
use App\Message\SuggestSubstitutes;
use App\Planning\Planner;
use App\Taste\IngredientMatch;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the text model what can stand in for the described foods nobody has asked about yet (not
 * the staples: salt, water, oil…), up to 40 per call, best first, at most three each. Chains
 * batches like DescribeFoodsHandler; each call is logged in extraction_run (step "substitutes").
 */
#[AsMessageHandler]
final readonly class SuggestSubstitutesHandler
{
    private const int BATCH = 40;
    private const int MAX = 3;
    private const string TOOL = 'save_substitutes';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(SuggestSubstitutes $message): void
    {
        $household = $this->entityManager->find(Household::class, Uuid::fromString($message->householdId));
        if (null === $household) {
            return;
        }
        $pending = $this->pending(self::BATCH);
        if ([] === $pending) {
            return;
        }

        $lines = array_map(static fn (Food $f): string => \sprintf('- %s (%s)', $f->getKey(), $f->getNameEs() ?? $f->getNameEn() ?? $f->getKey()), $pending);
        $started = hrtime(true);
        try {
            $completion = $this->llm->complete(new Prompt(
                model: $this->model,
                system: self::INSTRUCTIONS,
                // Not the catalog's keys: offered them, the model picks from what the household happens to cook.
                content: [Content::text("What can stand in for these foods?\n".implode("\n", $lines))],
                toolName: self::TOOL,
                toolDescription: 'Save what can stand in for each food.',
                toolSchema: self::schema(),
                maxTokens: 12000,
                thinking: false,
            ));
        } catch (LlmException $e) {
            $this->logger->warning('Substitutes failed: {error}', ['error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($household, null, 'substitutes', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->persist(new ExtractionRun(
            $household, null, 'substitutes', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));

        $byKey = [];
        foreach ($pending as $food) {
            $byKey[$food->getKey()] = $food;
        }
        $answered = 0;
        $answers = $completion->toolInput['foods'] ?? [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            if (!\is_array($answer) || !\is_string($answer['key'] ?? null)) {
                continue;
            }
            $food = $byKey[$answer['key']] ?? null;
            if (null === $food) {
                continue;
            }
            unset($byKey[$food->getKey()]);
            foreach (self::substitutes($food, $answer['substitutes'] ?? null) as $substitution) {
                $this->entityManager->persist($substitution);
            }
            $food->substitutesAsked();
            ++$answered;
        }
        $this->entityManager->flush();
        $this->logger->info('Substitutes for {count} foods', ['count' => $answered]);

        if ($answered > 0 && [] !== $this->pending(1)) {
            $this->bus->dispatch(new SuggestSubstitutes($message->householdId));
        }
    }

    /**
     * What the model says, cleaned: real keys, not the food itself, once each, a ratio between 0.2 and 5.
     *
     * @return list<FoodSubstitution>
     */
    public static function substitutes(Food $food, mixed $answers): array
    {
        $text = static fn (mixed $value, int $max): ?string => \is_string($value) && '' !== trim($value) ? mb_substr(trim($value), 0, $max) : null;
        $found = [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            if (!\is_array($answer)) {
                continue;
            }
            $key = IngredientMatch::cleanKeys([$answer['key'] ?? null])[0] ?? null;
            $es = $text($answer['name_es'] ?? null, 100);
            $en = $text($answer['name_en'] ?? null, 100);
            if (null === $key || null === $es || null === $en || $key === $food->getKey() || isset($found[$key])) {
                continue;
            }
            $ratio = is_numeric($answer['ratio'] ?? null) ? (float) $answer['ratio'] : 1.0;
            if ($ratio < 0.2 || $ratio > 5) {
                continue; // a pinch of something for a whole ingredient: not a substitute
            }
            $found[$key] = new FoodSubstitution($food, $key, $es, $en, round($ratio, 2), $text($answer['note_es'] ?? null, 120), $text($answer['note_en'] ?? null, 120), \count($found));
            if (self::MAX === \count($found)) {
                break;
            }
        }

        return array_values($found);
    }

    /** @return list<Food> */
    private function pending(int $limit): array
    {
        /** @var list<Food> $foods */
        $foods = $this->entityManager->createQueryBuilder()
            ->select('f')->from(Food::class, 'f')
            ->where('f.describedAt IS NOT NULL')->andWhere('f.substitutesAt IS NULL')->andWhere('f.key NOT IN (:staples)')
            ->setParameter('staples', Planner::STAPLES)
            ->orderBy('f.key')->setMaxResults($limit)
            ->getQuery()->getResult();

        return $foods;
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You help home cooks in Spain who are missing an ingredient, or would rather not use it. For each food (an English key and the name a recipe used), list what a good cook would reach for instead, so the dish still works: up to 3, best first, easy to find in a Spanish supermarket.
        - Only true substitutes, which play the same part in a dish: a leafy green for a leafy green, a melting cheese for a melting cheese, a pulse for a pulse, a stock for a stock. Fewer is better than a poor one; none when nothing really stands in (a whole chicken, the rice of a paella). Never something that only gives the colour, or a completely different dish.
        - key: canonical English snake_case, singular, the plainest name of the food ("greek_yogurt", "parsley", "turkey_breast"). Never the food itself.
        - name_es and name_en: the everyday name, lowercase ("yogur griego" / "greek yogurt").
        - ratio: grams of the substitute that replace 1 g of the food. Usually 1. Stronger substitutes less (dried oregano for fresh: 0.3; butter for olive oil: 1.2), weaker ones more. It must agree with the note.
        - note_es and note_en: a few words on how it changes the dish or what to add ("más ácido", "añade un chorrito de limón"); null when it hardly changes anything.
        - Avoid a substitute with a main allergen the food doesn't have (nuts, gluten, milk, egg, fish, shellfish) unless nothing else works.
        Answer by calling the save_substitutes tool once, with every food, even those with no substitutes.
        TXT;

    /** @return array<string, mixed> JSON Schema for strict tool use */
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];

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
                        'required' => ['key', 'substitutes'],
                        'properties' => [
                            'key' => $text,
                            'substitutes' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['key', 'name_es', 'name_en', 'ratio', 'note_es', 'note_en'],
                                    'properties' => [
                                        'key' => $text,
                                        'name_es' => $text,
                                        'name_en' => $text,
                                        'ratio' => ['type' => 'number'],
                                        'note_es' => $nullableText,
                                        'note_en' => $nullableText,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
