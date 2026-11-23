<?php

declare(strict_types=1);

namespace App\Food;

use App\Entity\ExtractionRun;
use App\Entity\Food;
use App\Entity\Household;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\Pricing;
use App\Llm\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the text model which ingredient keys of the food catalog are the same ingredient written
 * another way: in Spanish ("ajo" for "garlic"), plural, or with words swapped ("tomato_crushed"
 * for "crushed_tomato"). Only proposes: KeyMerger does the merging. Logged in extraction_run
 * (step "foods") for the costs page.
 */
final readonly class KeyReview
{
    private const string TOOL = 'save_merges';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @return list<array{from: string, to: string}> each "from" once; a "to" is never a "from"
     *
     * @throws \App\Llm\LlmException
     */
    public function propose(Household $payer): array
    {
        /** @var list<Food> $foods */
        $foods = $this->entityManager->getRepository(Food::class)->findBy([], ['key' => 'ASC']);
        if (\count($foods) < 2) {
            return [];
        }
        $lines = array_map(
            static fn (Food $f): string => \sprintf('- %s (%s / %s)', $f->getKey(), $f->getNameEs() ?? '?', $f->getNameEn() ?? '?'),
            $foods,
        );

        $started = hrtime(true);
        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::INSTRUCTIONS,
            content: [Content::text("The catalog:\n".implode("\n", $lines))],
            toolName: self::TOOL,
            toolDescription: 'Save the keys to merge into another.',
            toolSchema: self::schema(),
            maxTokens: 8000,
            thinking: false,
        ));
        $this->entityManager->persist(new ExtractionRun(
            $payer, null, 'foods', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, (int) ((hrtime(true) - $started) / 1_000_000),
        ));
        $this->entityManager->flush();

        $known = array_map(static fn (Food $f): string => $f->getKey(), $foods);
        $answers = $completion->toolInput['merges'] ?? [];

        return self::clean(\is_array($answers) ? $answers : [], $known);
    }

    /**
     * Keeps proposals that make sense: from a known key, to a well-formed different one, each key
     * merged once, and no chains (a → b, b → c becomes a → c, b → c).
     *
     * @param array<mixed> $answers
     * @param list<string> $known
     *
     * @return list<array{from: string, to: string}>
     */
    public static function clean(array $answers, array $known): array
    {
        $into = [];
        foreach ($answers as $answer) {
            $from = \is_array($answer) && \is_string($answer['from'] ?? null) ? $answer['from'] : '';
            $to = \is_array($answer) && \is_string($answer['to'] ?? null) ? trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($answer['to'])), '_') : '';
            if (\in_array($from, $known, true) && '' !== $to && \strlen($to) <= 80 && $from !== $to && !isset($into[$from])) {
                $into[$from] = $to;
            }
        }
        $merges = [];
        foreach ($into as $from => $to) {
            for ($hops = 0; isset($into[$to]) && $hops < 5; ++$hops) {
                $to = $into[$to];
            }
            if ($from !== $to) {
                $merges[] = ['from' => (string) $from, 'to' => $to];
            }
        }

        return $merges;
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You tidy the ingredient catalog of a meal planner. Keys must be lowercase English snake_case, singular, with the plain name a shop uses, the same for every recipe, so a shopping list can add up "cebolla" and "onion".
        Find the keys that break this and say which key each should become:
        - A key in Spanish or another language: the English key ("ajo" → "garlic", "nata_para_cocinar" → "cooking_cream"). If the catalog already has the English one, use it exactly.
        - The same ingredient written another way: plural, words swapped, a needless word ("cherry_tomatoes" → "cherry_tomato", "tomato_crushed" → "crushed_tomato", "boursin_cheese" → "boursin"). Merge into the key already in the catalog that reads best.
        Never merge different products, even close ones: sweet and smoked paprika, chicken and vegetable broth, fresh and dried herbs, canned and dried pulses, olive and sunflower oil, a generic key ("broth", "cheese") and a specific one stay apart. When unsure, leave it.
        Answer by calling the save_merges tool once; an empty list when nothing needs merging.
        TXT;

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['merges'],
            'properties' => [
                'merges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['from', 'to'],
                        'properties' => [
                            'from' => ['type' => 'string', 'description' => 'A key of the catalog.'],
                            'to' => ['type' => 'string', 'description' => 'The key it should become: one of the catalog when it is there, else a new English key.'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
