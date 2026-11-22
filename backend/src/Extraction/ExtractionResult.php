<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Enum\Language;
use App\Enum\MealType;
use App\Llm\Completion;

/**
 * A model's answer, checked and typed. The model's JSON is never trusted as is: anything
 * malformed is dropped or clamped rather than stored.
 */
final readonly class ExtractionResult
{
    /**
     * @param list<ExtractedRecipe> $recipes
     * @param list<string>          $missingFields
     */
    public function __construct(
        public string $status,
        public ?string $message,
        public float $confidence,
        public array $missingFields,
        public array $recipes,
        public Completion $completion,
    ) {
    }

    public function needsReview(): bool
    {
        return 'partial' === $this->status || $this->confidence < 0.8 || [] !== $this->missingFields;
    }

    /** @throws ExtractionException when the model didn't answer with the tool */
    public static function fromCompletion(Completion $completion): self
    {
        $input = $completion->toolInput;
        if (null === $input) {
            throw new ExtractionException(\sprintf('The model answered without calling %s (stop reason: %s).', ExtractionSchema::TOOL_NAME, $completion->stopReason), $completion);
        }

        $status = \is_string($input['status'] ?? null) && \in_array($input['status'], ExtractionSchema::STATUSES, true) ? $input['status'] : 'partial';
        $recipes = [];
        foreach (\array_slice(self::list($input['recipes'] ?? null), 0, 5) as $recipe) {
            if (\is_array($recipe)) {
                $parsed = self::recipe($recipe);
                if (null !== $parsed) {
                    $recipes[] = $parsed;
                }
            }
        }
        // Recipes sent as text that isn't JSON: unreadable, not "no recipe" (the handler retries).
        $raw = $input['recipes'] ?? null;
        if ([] === $recipes && \is_string($raw) && '' !== trim($raw) && 'no_recipe' !== $status) {
            throw new ExtractionException(\sprintf('The model sent the recipes as unreadable text (%s…).', mb_substr($raw, 0, 80)), $completion);
        }
        if ([] === $recipes && \in_array($status, ['complete', 'partial'], true)) {
            $status = 'no_recipe';
        }

        return new self(
            status: $status,
            message: self::string($input['message'] ?? null, 300),
            confidence: is_numeric($input['confidence'] ?? null) ? max(0.0, min(1.0, (float) $input['confidence'])) : 0.5,
            missingFields: array_values(array_filter(array_map(static fn (mixed $f): ?string => self::string($f, 40), self::list($input['missing_fields'] ?? null)))),
            recipes: $recipes,
            completion: $completion,
        );
    }

    /** @param array<mixed> $data */
    private static function recipe(array $data): ?ExtractedRecipe
    {
        $title = self::string($data['title'] ?? null, 200);
        if (null === $title) {
            return null;
        }

        $ingredients = [];
        foreach (\array_slice(self::list($data['ingredients'] ?? null), 0, 150) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $raw = self::string($item['raw'] ?? null, 255) ?? self::string($item['name'] ?? null, 255);
            if (null === $raw) {
                continue;
            }
            $unit = \is_string($item['unit'] ?? null) ? strtolower($item['unit']) : null;
            $key = self::string($item['canonical_key'] ?? null, 80);
            $ingredients[] = [
                'raw' => $raw,
                'group' => self::string($item['group'] ?? null, 100),
                'quantity' => is_numeric($item['quantity'] ?? null) && (float) $item['quantity'] >= 0 ? round((float) $item['quantity'], 3) : null,
                'unit' => \in_array($unit, ExtractionSchema::UNITS, true) ? $unit : null,
                'name' => self::string($item['name'] ?? null, 150),
                'canonicalKey' => null === $key ? null : trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_'),
                'prep' => self::string($item['prep'] ?? null, 150),
                'optional' => true === ($item['optional'] ?? false),
                // Only asked for when estimates are allowed (ExtractionSchema::schema(estimates: true)).
                'estimated' => true === ($item['estimated'] ?? false) && is_numeric($item['quantity'] ?? null),
            ];
        }

        $mealTypes = array_values(array_filter(array_map(
            static fn (mixed $type): ?MealType => \is_string($type) ? MealType::tryFrom($type) : null,
            self::list($data['meal_types'] ?? null),
        )));

        return new ExtractedRecipe(
            title: $title,
            language: Language::tryFrom(\is_string($data['language'] ?? null) ? $data['language'] : '') ?? Language::Es,
            servings: $servings = self::int($data['servings'] ?? null, 1, 50),
            prepMinutes: self::int($data['prep_minutes'] ?? null, 0, 1440),
            cookMinutes: self::int($data['cook_minutes'] ?? null, 0, 1440),
            mealTypes: $mealTypes,
            tags: \array_slice(array_values(array_filter(array_map(static fn (mixed $t): ?string => self::string($t, 40), self::list($data['tags'] ?? null)))), 0, 8),
            ingredients: $ingredients,
            steps: \array_slice(array_values(array_filter(array_map(static fn (mixed $s): ?string => self::string($s, 4000), self::list($data['steps'] ?? null)))), 0, 100),
            notes: self::string($data['notes'] ?? null, 10000),
            servingsEstimated: null !== $servings && true === ($data['servings_estimated'] ?? false),
        );
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        // Models sometimes send a nested array as a JSON-encoded string ("recipes": "[{...}]").
        if (\is_string($value) && \in_array(ltrim($value)[0] ?? '', ['[', '{'], true)) {
            $value = json_decode($value, true);
            if (\is_array($value) && !array_is_list($value)) {
                $value = [$value];
            }
        }

        return \is_array($value) ? array_values($value) : [];
    }

    private static function string(mixed $value, int $max): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $max);
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int) round((float) $value);

        return $int < $min || $int > $max ? null : $int;
    }
}
