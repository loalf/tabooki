<?php

declare(strict_types=1);

namespace App\Food\Bedca;

use App\Entity\ExtractionRun;
use App\Entity\Food;
use App\Entity\Household;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Llm\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Has the text model pair each catalog food with the BEDCA food that is the same thing in the same
 * state (boiled chickpeas with "Garbanzo, hervido", not the dried ones), or with none. Only
 * proposes: app:foods:bedca shows the pairs before importing. Logged as step "bedca".
 */
final readonly class BedcaMatcher
{
    private const string TOOL = 'save_matches';
    /** Foods per call: fewer foods against the whole table pair more carefully. */
    private const int BATCH = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @param list<Food>      $foods
     * @param list<BedcaFood> $bedca
     *
     * @return array<string, BedcaFood> by food key; foods without a match left out
     *
     * @throws LlmException
     */
    public function match(array $foods, array $bedca, Household $payer): array
    {
        $matches = [];
        foreach (array_chunk($foods, self::BATCH) as $batch) {
            $matches += $this->matchBatch($batch, $bedca, $payer);
        }

        return $matches;
    }

    /**
     * @param list<Food>      $foods
     * @param list<BedcaFood> $bedca
     *
     * @return array<string, BedcaFood>
     */
    private function matchBatch(array $foods, array $bedca, Household $payer): array
    {
        if ([] === $foods || [] === $bedca) {
            return [];
        }
        // Answered by name, not id: a name copied wrong isn't in the table, a number copied wrong is another food.
        $byName = [];
        foreach ($bedca as $food) {
            $byName[mb_strtolower($food->nameEs)] = $food;
        }
        $catalog = array_map(static fn (Food $f): string => \sprintf('- %s (%s / %s)', $f->getKey(), $f->getNameEs() ?? '?', $f->getNameEn() ?? '?'), $foods);
        $table = array_map(static fn (BedcaFood $b): string => \sprintf('%s | %s', $b->nameEs, $b->nameEn), $bedca);

        $started = hrtime(true);
        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::INSTRUCTIONS,
            content: [Content::text("Our foods:\n".implode("\n", $catalog)."\n\nBEDCA (Spanish | English):\n".implode("\n", $table))],
            toolName: self::TOOL,
            toolDescription: 'Save the BEDCA food that is each of our foods, or null.',
            toolSchema: self::schema(),
            maxTokens: 8000,
            thinking: false,
        ));
        $this->entityManager->persist(new ExtractionRun(
            $payer, null, 'bedca', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, (int) ((hrtime(true) - $started) / 1_000_000),
        ));
        $this->entityManager->flush();

        $keys = array_flip(array_map(static fn (Food $f): string => $f->getKey(), $foods));
        $matches = [];
        $answers = $completion->toolInput['matches'] ?? [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            $key = \is_array($answer) && \is_string($answer['key'] ?? null) ? $answer['key'] : null;
            $name = \is_array($answer) && \is_string($answer['bedca'] ?? null) ? mb_strtolower(trim($answer['bedca'])) : null;
            if (null !== $key && isset($keys[$key]) && null !== $name && isset($byName[$name])) {
                $matches[$key] = $byName[$name];
            }
        }

        return $matches;
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You link a meal planner's foods to the Spanish food composition database (BEDCA), to use its figures. For each of our foods (an English key, as a recipe uses it, and its names) give the Spanish name of the BEDCA food that is the same food in the same state, copied exactly from the table, or null.
        - Same state: raw, boiled/cooked, canned (drained), dried, frozen, as the key and names say ("chickpeas_cooked" is "Garbanzo, hervido"; "chickpeas_canned" is "Garbanzo, en conserva"; a key without a state is the form a recipe usually uses: raw vegetables, dried pasta and rice, raw meat).
        - Same food, not a relative: no "pimiento" for "chili_pepper", no "queso" for "mozzarella" unless BEDCA has that cheese, no single spice for a spice mix. When unsure, null: a wrong match is worse than none.
        - Close variants are fine when the figures barely differ: "Aceite de oliva" for "extra_virgin_olive_oil", "Cebolla" for "onion", a generic "Tomate" for "tomato", "Pasta alimenticia, cruda" for any dried pasta shape. Not a different oil, fruit, vegetable or seafood ("Naranja" isn't lemon, "Sepia" isn't squid).
        - Look through the whole table before answering null: cream for cooking is "Nata…", peppers are "Pimiento…", paprika is "Pimentón…".
        Answer by calling the save_matches tool once, with every one of our foods.
        TXT;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['matches'],
            'properties' => [
                'matches' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'bedca'],
                        'properties' => ['key' => ['type' => 'string'], 'bedca' => ['type' => ['string', 'null'], 'description' => 'The BEDCA Spanish name, exactly as in the table']],
                    ],
                ],
            ],
        ];
    }
}
