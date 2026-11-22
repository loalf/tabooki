<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Recipe;
use App\Llm\Completion;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads recipes against the household's free-text notes, the wishes no rule can say ("nothing
 * fiddly on weekdays", "the kids don't like saucy dishes"): good, neutral or poor for each, for
 * which meals, and why in a few words (docs/PLAN.md §6.3). One call for a batch of recipes, a
 * short line each; the planner then reads the stored answers, never the model.
 */
final readonly class NotesJudge
{
    public const string TOOL = 'save_judgements';
    public const int BATCH = 100;
    public const array FITS = ['good', 'neutral', 'poor'];
    public const array WHEN = ['always', 'weekdays', 'weekends', 'lunch', 'dinner'];

    public function __construct(
        private LlmClient $llm,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @param list<Recipe> $recipes at most BATCH
     *
     * @return array{list<array{fit: string, when: string, reason: array{es: string, en: string}|null}>, Completion} one judgement per recipe, in order (neutral when the model left one out)
     *
     * @throws LlmException
     */
    public function judge(string $notes, array $recipes): array
    {
        $lines = [];
        foreach ($recipes as $n => $recipe) {
            $lines[] = self::line($n + 1, $recipe);
        }

        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::INSTRUCTIONS,
            content: [Content::text("The household's wishes:\n".trim($notes)."\n\nThe recipes:\n".implode("\n", $lines))],
            toolName: self::TOOL,
            toolDescription: 'Save how well each recipe fits the household\'s wishes.',
            toolSchema: self::schema(),
            maxTokens: 8000,
            thinking: false,
        ));

        $judgements = array_fill(0, \count($recipes), ['fit' => 'neutral', 'when' => 'always', 'reason' => null]);
        $answers = $completion->toolInput['judgements'] ?? [];
        foreach (\is_array($answers) ? $answers : [] as $answer) {
            $n = \is_array($answer) && \is_int($answer['n'] ?? null) ? $answer['n'] - 1 : -1;
            if (!isset($judgements[$n]) || !\in_array($answer['fit'] ?? null, self::FITS, true)) {
                continue;
            }
            $when = \in_array($answer['when'] ?? null, self::WHEN, true) ? $answer['when'] : 'always';
            $es = \is_string($answer['reason_es'] ?? null) ? mb_substr(trim($answer['reason_es']), 0, 60) : '';
            $en = \is_string($answer['reason_en'] ?? null) ? mb_substr(trim($answer['reason_en']), 0, 60) : '';
            $neutral = 'neutral' === $answer['fit'];
            $judgements[$n] = [
                'fit' => $answer['fit'],
                'when' => $neutral ? 'always' : $when,
                'reason' => $neutral || ('' === $es && '' === $en) ? null : ['es' => '' === $es ? $en : $es, 'en' => '' === $en ? $es : $en],
            ];
        }

        return [$judgements, $completion];
    }

    /** "3. Lentejas estofadas | 75 min | 6 steps | lentils, chorizo, carrot | tags: legumbres, guiso" */
    private static function line(int $n, Recipe $recipe): string
    {
        $names = [];
        foreach ($recipe->getIngredients() as $ingredient) {
            if (!$ingredient->isOptional() && \count($names) < 15) {
                $names[] = $ingredient->getName() ?? $ingredient->getCanonicalKey() ?? $ingredient->getRawText();
            }
        }
        $parts = [
            "{$n}. {$recipe->getTitle()}",
            null === $recipe->getTotalMinutes() ? 'time unknown' : $recipe->getTotalMinutes().' min',
            \count($recipe->getSteps()).' steps',
            implode(', ', $names),
        ];
        if ([] !== $recipe->getTags()) {
            $parts[] = 'tags: '.implode(', ', $recipe->getTags());
        }

        return mb_substr(implode(' | ', $parts), 0, 400);
    }

    private const string INSTRUCTIONS = <<<'TXT'
        A household in Spain plans its weekly meals from its own recipes. It wrote some wishes no simple rule covers. For each numbered recipe, say how it fits those wishes, judging only against them (not against general taste, health or anything else):
        - fit: "poor" when it clearly goes against a wish, "good" when it clearly matches one, "neutral" otherwise. Most recipes are neutral.
        - when: which meals the judgement is about. "always", or only "weekdays", "weekends", "lunch" or "dinner" when the wish is only about those ("nothing fiddly on weekdays": a 90-minute recipe is poor on "weekdays"; "light dinners": a heavy stew is poor at "dinner").
        - reason_es and reason_en: 2 to 5 words naming the wish it meets or breaks, in Spanish and in English ("Laboriosa para entre semana" / "Fiddly for a weekday", "Mucha salsa" / "Lots of sauce"). Empty for neutral.
        Judge every recipe, by its number. Answer by calling the save_judgements tool once.
        TXT;

    /** @return array<string, mixed> JSON Schema for strict tool use */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['judgements'],
            'properties' => [
                'judgements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['n', 'fit', 'when', 'reason_es', 'reason_en'],
                        'properties' => [
                            'n' => ['type' => 'integer'],
                            'fit' => ['type' => 'string', 'enum' => self::FITS],
                            'when' => ['type' => 'string', 'enum' => self::WHEN],
                            'reason_es' => ['type' => 'string'],
                            'reason_en' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
