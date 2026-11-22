<?php

declare(strict_types=1);

namespace App\Translation;

use App\Enum\Language;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Translates a recipe's text into the other language with the text model, line for line: the
 * translation shares the recipe's amounts, so every ingredient line and step has to stay where it is.
 */
final readonly class RecipeTranslator
{
    public const string TOOL = 'save_translation';

    private const array NAMES = ['es' => 'Spanish', 'en' => 'English'];

    public function __construct(
        private LlmClient $llm,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @throws LlmException         the call failed
     * @throws TranslationException the answer doesn't line up with the recipe
     */
    public function translate(RecipeText $text): Translated
    {
        $target = $text->language->other();
        $source = [
            'title' => $text->title,
            'tags' => $text->tags,
            'ingredients' => array_map(static fn (array $line): array => [
                'line' => $line['rawText'], 'name' => $line['name'], 'prep' => $line['prep'], 'group' => $line['groupName'],
            ], $text->ingredients),
            'steps' => $text->steps,
            'notes' => $text->notes,
        ];

        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::instructions($text->language, $target, \count($text->ingredients), \count($text->steps)),
            content: [Content::text(json_encode($source, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT))],
            toolName: self::TOOL,
            toolDescription: \sprintf('Save the recipe written in %s.', self::NAMES[$target->value]),
            toolSchema: self::schema(),
            maxTokens: 12000,
            thinking: false,
        ));

        $translated = self::read($completion->toolInput ?? [], $target);
        if (null === $translated || !$text->matches($translated) || '' === $translated->title) {
            throw new TranslationException(\sprintf('The translation doesn\'t line up with the recipe (%d ingredients and %d steps expected).', \count($text->ingredients), \count($text->steps)));
        }

        return new Translated($translated, $completion);
    }

    /** @param array<mixed> $input */
    private static function read(array $input, Language $language): ?RecipeText
    {
        $string = static fn (mixed $value): ?string => \is_string($value) ? $value : null;
        $strings = static fn (mixed $list): array => \is_array($list) ? array_values(array_filter($list, \is_string(...))) : [];
        $ingredients = [];
        foreach (\is_array($input['ingredients'] ?? null) ? $input['ingredients'] : [] as $line) {
            $raw = \is_array($line) ? $string($line['line'] ?? null) : null;
            if (null === $raw || '' === trim($raw)) {
                return null;
            }
            $ingredients[] = ['rawText' => mb_substr($raw, 0, 255), 'name' => $string($line['name'] ?? null), 'prep' => $string($line['prep'] ?? null), 'groupName' => $string($line['group'] ?? null)];
        }
        if (!\is_string($input['title'] ?? null)) {
            return null;
        }

        return new RecipeText($language, mb_substr($input['title'], 0, 200), $strings($input['tags'] ?? null), $strings($input['steps'] ?? null), $string($input['notes'] ?? null), $ingredients);
    }

    private static function instructions(Language $from, Language $to, int $ingredients, int $steps): string
    {
        $source = self::NAMES[$from->value];
        $target = self::NAMES[$to->value];
        $units = Language::Es === $to
            ? 'Kitchen units read the Spanish way: tbsp → cucharada, tsp → cucharadita, pinch → pizca, clove → diente, can → lata. Use "de" where Spanish needs it ("450 g de salchicha").'
            : 'Kitchen units read the English way: cucharada → tbsp, cucharadita → tsp, pizca → pinch, diente → clove, lata → can ("450 g sausage", not "450 g of sausage").';

        return <<<TXT
            You translate the recipes of a household in Spain that cooks in Spanish and English. Translate this recipe from {$source} into {$target}, the way a cook writing in {$target} would: natural dish and ingredient names (not word for word), the usual kitchen wording for the steps.
            - Keep every ingredient line and every step, one for one and in the same order: {$ingredients} ingredients and {$steps} steps. Never merge, split, add or drop any.
            - Keep every amount and number exactly as it is: metric stays metric, nothing is converted or rounded. A leading "~" marks an amount someone estimated: keep it.
            - {$units}
            - Each ingredient has its full line, the ingredient's name alone, how it is prepared ("finely chopped") and the sub-heading it is under (group). Translate each; null stays null.
            - Tags: short, lowercase, in {$target}.
            - Names with no translation (a brand, "pad thai", "ramen") stay as they are.
            Answer by calling the save_translation tool once.
            TXT;
    }

    /** @return array<string, mixed> JSON Schema for strict tool use (every property required, no extra ones) */
    public static function schema(): array
    {
        $nullable = static fn (string $description): array => ['type' => ['string', 'null'], 'description' => $description];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'tags', 'ingredients', 'steps', 'notes'],
            'properties' => [
                'title' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'ingredients' => [
                    'type' => 'array',
                    'description' => 'One for each ingredient of the recipe, in the same order.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['line', 'name', 'prep', 'group'],
                        'properties' => [
                            'line' => ['type' => 'string', 'description' => 'The whole line, with its amount: "450 g sausage".'],
                            'name' => $nullable('The ingredient alone: "sausage".'),
                            'prep' => $nullable('How it is prepared: "finely chopped".'),
                            'group' => $nullable('The sub-heading it is under: "For the sauce".'),
                        ],
                    ],
                ],
                'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'One for each step of the recipe, in the same order.'],
                'notes' => $nullable('The notes, or null if the recipe has none.'),
            ],
        ];
    }
}
