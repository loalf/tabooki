<?php

declare(strict_types=1);

namespace App\Taste;

use App\Llm\Completion;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the questionnaire into rules with the text model (docs/PLAN.md §6.3): it starts from
 * what the tappable answers say (TasteRules::fromAnswers()), maps what they can't (an ingredient,
 * "more legumes") onto the household's own ingredient keys and tags, reads the free text, writes
 * the summary, and asks up to three follow-ups where something is unclear. One call, no thinking.
 */
final readonly class TasteCompiler
{
    public const string TOOL = 'save_taste_profile';
    public const int MAX_QUESTIONS = 3;

    public function __construct(
        private LlmClient $llm,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @param array<mixed>          $answers     the questionnaire (frontend/src/api/taste.ts, TasteAnswers)
     * @param array<string, string> $ingredients the household's ingredient keys, with a name its recipes use
     * @param array<string, int>    $tags        the household's English recipe tags, with how many recipes have each
     * @param bool                  $final       the follow-ups were answered already: no more questions
     *
     * @return array{TasteDraft, Completion}
     *
     * @throws LlmException
     */
    public function compile(array $answers, array $ingredients, array $tags, string $locale, bool $final): array
    {
        $draft = TasteRules::fromAnswers($answers);
        $input = [
            'answers' => $answers,
            'draft_rules' => $draft->toArray(),
            'household_ingredients' => array_map(static fn (string $key, string $name): string => "{$key} ({$name})", array_keys($ingredients), $ingredients),
            'household_tags' => $tags,
        ];

        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::instructions($locale, $final),
            content: [Content::text(json_encode($input, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT))],
            toolName: self::TOOL,
            toolDescription: 'Save the household\'s likings as rules, notes and a summary, and any follow-up questions.',
            toolSchema: self::schema(),
            maxTokens: 4000,
            thinking: false,
        ));

        return [$this->read($completion->toolInput ?? [], $draft, $final), $completion];
    }

    /** @param array<mixed> $output */
    private function read(array $output, TasteRules $draft, bool $final): TasteDraft
    {
        $rules = \is_array($output['rules'] ?? null) ? TasteRules::fromArray($output['rules']) : $draft;
        $rules = self::keepFirmAnswers($rules, $draft);

        $notes = \is_string($output['notes'] ?? null) ? trim($output['notes']) : '';
        $summary = $output['summary'] ?? null;
        $summary = \is_array($summary) && \is_string($summary['es'] ?? null) && \is_string($summary['en'] ?? null)
            ? ['es' => mb_substr(trim($summary['es']), 0, 1000), 'en' => mb_substr(trim($summary['en']), 0, 1000)]
            : null;

        $questions = [];
        foreach ($final || !\is_array($output['questions'] ?? null) ? [] : $output['questions'] as $question) {
            $text = \is_array($question) && \is_string($question['question'] ?? null) ? trim($question['question']) : '';
            $options = \is_array($question) && \is_array($question['options'] ?? null)
                ? array_values(array_filter(array_map(static fn (mixed $o): string => \is_string($o) ? mb_substr(trim($o), 0, 120) : '', $question['options'])))
                : [];
            if ('' !== $text && \count($options) >= 2) {
                $questions[] = ['question' => mb_substr($text, 0, 300), 'options' => \array_slice($options, 0, 4)];
            }
        }

        return new TasteDraft($rules, '' === $notes ? null : mb_substr($notes, 0, 2000), $summary, \array_slice($questions, 0, self::MAX_QUESTIONS));
    }

    /**
     * What the household tapped as an allergy or its diet stays, whatever the model says: only
     * the household removes it, on the review screen.
     */
    private static function keepFirmAnswers(TasteRules $rules, TasteRules $draft): TasteRules
    {
        $data = $rules->toArray();
        $data['diet'] ??= $draft->diet?->value;
        foreach ($draft->excludeGroups as $rule) {
            if (Strength::Never === $rule->strength) {
                $data['excludeGroups'][] = $rule->toArray();
            }
        }

        return TasteRules::fromArray($data);
    }

    private static function instructions(string $locale, bool $final): string
    {
        $language = 'en' === $locale ? 'English' : 'Spanish';
        $questions = $final
            ? '- questions: always empty (the follow-ups have been answered: they are in answers.followUps).'
            : "- questions: up to 3 multiple-choice follow-ups, in {$language}, only where an answer is ambiguous and the reply would change a rule (\"no cheese\": melted on a pizza too?). 2–4 short options each. Ground them in the household's own recipes when it helps. Empty when nothing is unclear, which is the usual case.";

        return <<<TXT
            You help a household in Spain tell its meal planner what it likes to eat. You get its answers to a short questionnaire, draft rules already derived from the tappable answers, and the ingredient keys and English tags its recipes use. Answer with the final rules, notes and a summary.
            - Start from draft_rules and keep them, unless the free text (answers.other) or the follow-up answers (answers.followUps) clearly change them.
            - answers.avoid items that aren't a food group of the schema (custom: true, or things like mushrooms, coriander, olives) become excludeIngredients with the same strength: keys are the matching keys from household_ingredients plus the usual canonical English snake_case keys for that ingredient (singular, e.g. "coriander", "fresh_coriander"); name in Spanish and English, short and lowercase.
            - answers.more become prefer rules with weight "more": tags are the household_tags that mean it (legumes: legumes, chickpeas, lentils, beans…); if none fits, the usual English words. answers.less, the same with weight "less".
            - From the free text, make a rule of anything a rule can say: a food group or an ingredient to leave out (an allergy or something they can't eat is "never"; a dislike is "avoid"), a diet, a weekly limit ("meat at most twice" counts red_meat, pork, poultry and processed_meat; with fish too it's also fish and shellfish), more or less of something, how filling lunch or dinner should be (mealHeaviness), how spicy.
            - notes: what they said that no rule can express ("nothing fiddly on weekdays", "the kids don't like sauces"), short, in their words' language; null when there is nothing left.
            - summary: es (Spanish, speaking to them as "vosotros") and en (English, "you"): 2–4 short sentences covering every rule and the notes, saying exactly what the rules say and nothing more (a rule about fish isn't one about shellfish). "Coméis de todo menos pescado y marisco (alergia). Carne o pescado, como mucho dos veces por semana…"
            {$questions}
            Answer by calling the save_taste_profile tool once.
            TXT;
    }

    /** @return array<string, mixed> JSON Schema for strict tool use */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['rules', 'notes', 'summary', 'questions'],
            'properties' => [
                'rules' => TasteRules::schema(),
                'notes' => ['type' => ['string', 'null']],
                'summary' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['es', 'en'], 'properties' => ['es' => $text, 'en' => $text]],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['question', 'options'],
                        'properties' => ['question' => $text, 'options' => ['type' => 'array', 'items' => $text]],
                    ],
                ],
            ],
        ];
    }
}
