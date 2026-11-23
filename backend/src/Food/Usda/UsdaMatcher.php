<?php

declare(strict_types=1);

namespace App\Food\Usda;

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
 * Pairs catalog foods with USDA foods: each is searched by its key in words, and the text model
 * picks the result that is the same food in the same state, or none (answering with its exact
 * description, looked up in that food's own results). Logged as step "usda".
 */
final readonly class UsdaMatcher
{
    private const string TOOL = 'save_matches';
    private const int BATCH = 15;

    public function __construct(
        private UsdaClient $usda,
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @param list<Food> $foods
     *
     * @return array<string, UsdaFood> by food key; foods without a match left out
     *
     * @throws LlmException
     */
    public function match(array $foods, Household $payer): array
    {
        $matches = [];
        foreach (array_chunk($foods, self::BATCH) as $batch) {
            $candidates = [];
            foreach ($batch as $food) {
                $candidates[$food->getKey()] = $this->usda->search(self::query($food));
            }
            $matches += $this->pick($batch, $candidates, $payer);
        }

        return $matches;
    }

    /** What to search for: its key in words ("chicken broth"); keys are plain English, names not always ("chicken broth or water"). */
    public static function query(Food $food): string
    {
        return str_replace('_', ' ', $food->getKey());
    }

    /**
     * @param list<Food>                    $foods
     * @param array<string, list<UsdaFood>> $candidates
     *
     * @return array<string, UsdaFood>
     */
    private function pick(array $foods, array $candidates, Household $payer): array
    {
        $lines = [];
        foreach ($foods as $food) {
            $lines[] = \sprintf('## %s (%s / %s)', $food->getKey(), $food->getNameEs() ?? '?', $food->getNameEn() ?? '?');
            foreach ($candidates[$food->getKey()] ?? [] as $candidate) {
                $lines[] = \sprintf('- %s [%s]', $candidate->description, $candidate->dataType);
            }
            if ([] === ($candidates[$food->getKey()] ?? [])) {
                $lines[] = '(no results)';
            }
        }

        $started = hrtime(true);
        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::INSTRUCTIONS,
            content: [Content::text(implode("\n", $lines))],
            toolName: self::TOOL,
            toolDescription: 'Save the USDA result that is each of our foods, or null.',
            toolSchema: self::schema(),
            maxTokens: 4000,
            thinking: false,
        ));
        $this->entityManager->persist(new ExtractionRun(
            $payer, null, 'usda', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, (int) ((hrtime(true) - $started) / 1_000_000),
        ));
        $this->entityManager->flush();

        $matches = [];
        $answers = $completion->toolInput['matches'] ?? [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            $key = \is_array($answer) && \is_string($answer['key'] ?? null) ? $answer['key'] : null;
            $description = \is_array($answer) && \is_string($answer['usda'] ?? null) ? mb_strtolower(trim($answer['usda'])) : null;
            if (null === $key || null === $description) {
                continue;
            }
            foreach ($candidates[$key] ?? [] as $candidate) {
                if (mb_strtolower($candidate->description) === $description) {
                    $matches[$key] = $candidate;
                    break;
                }
            }
        }

        return $matches;
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You link a meal planner's foods to USDA FoodData Central, to use its figures. Each of our foods (an English key, as a recipe uses it, and its names) comes with USDA's search results for it. Pick the result that is the same food in the same state, copying its description exactly, or null.
        - Same state: raw, cooked, canned (drained), dried, as a stock or broth ready to use (not the powder or cube), as the key and names say; a key without a state is the form a recipe usually uses.
        - Same food: a generic result (Foundation, SR Legacy, Survey) when one is right; a Branded product only for a product (gochujang, halloumi, a sauce), and then the plain one, not a meal or a flavoured variant.
        - When no result is right, null: a wrong match is worse than none.
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
                        'required' => ['key', 'usda'],
                        'properties' => ['key' => ['type' => 'string'], 'usda' => ['type' => ['string', 'null'], 'description' => 'The USDA description, exactly as in the results']],
                    ],
                ],
            ],
        ];
    }
}
