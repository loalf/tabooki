<?php

declare(strict_types=1);

namespace App\Translation;

use App\Entity\Recipe;
use App\Enum\Language;

/**
 * Everything of a recipe that is written in a language: title, tags, ingredient lines (and their
 * name, preparation and group), steps and notes. Amounts, units, servings and times aren't here:
 * both languages share them. Values are normalized the way the recipe stores them, so a text read
 * back from an edit compares equal to the one that was shown.
 */
final readonly class RecipeText
{
    /** @var list<string> */
    public array $tags;

    /** @var list<string> */
    public array $steps;

    public ?string $notes;

    /** @var list<array{rawText: string, name: ?string, prep: ?string, groupName: ?string}> */
    public array $ingredients;

    public string $title;

    /**
     * @param list<string>                                                                   $tags
     * @param list<string>                                                                   $steps
     * @param list<array{rawText: string, name: ?string, prep: ?string, groupName: ?string}> $ingredients
     */
    public function __construct(
        public Language $language,
        string $title,
        array $tags,
        array $steps,
        ?string $notes,
        array $ingredients,
    ) {
        $this->title = trim($title);
        $this->tags = Recipe::normalizeTags($tags);
        $this->steps = Recipe::normalizeSteps($steps);
        $this->notes = self::optional($notes);
        $this->ingredients = array_map(static fn (array $line): array => [
            'rawText' => trim($line['rawText']),
            'name' => self::optional($line['name']),
            'prep' => self::optional($line['prep']),
            'groupName' => self::optional($line['groupName']),
        ], $ingredients);
    }

    public static function of(Recipe $recipe): self
    {
        $ingredients = [];
        foreach ($recipe->getIngredients() as $ingredient) {
            $ingredients[] = [
                'rawText' => $ingredient->getRawText(),
                'name' => $ingredient->getName(),
                'prep' => $ingredient->getPrep(),
                'groupName' => $ingredient->getGroupName(),
            ];
        }

        return new self($recipe->getLanguage(), $recipe->getTitle(), $recipe->getTags(), $recipe->getSteps(), $recipe->getNotes(), $ingredients);
    }

    /**
     * Reads back what toArray() wrote (the stored translation), or null when it isn't that shape.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $language = Language::tryFrom(\is_string($data['language'] ?? null) ? $data['language'] : '');
        if (null === $language || !\is_string($data['title'] ?? null)) {
            return null;
        }
        $strings = static fn (mixed $list): array => \is_array($list) ? array_values(array_filter($list, \is_string(...))) : [];
        $string = static fn (mixed $value): ?string => \is_string($value) ? $value : null;
        $ingredients = [];
        foreach (\is_array($data['ingredients'] ?? null) ? $data['ingredients'] : [] as $line) {
            if (!\is_array($line) || !\is_string($line['rawText'] ?? null)) {
                return null;
            }
            $ingredients[] = ['rawText' => $line['rawText'], 'name' => $string($line['name'] ?? null), 'prep' => $string($line['prep'] ?? null), 'groupName' => $string($line['groupName'] ?? null)];
        }

        return new self($language, $data['title'], $strings($data['tags'] ?? null), $strings($data['steps'] ?? null), $string($data['notes'] ?? null), $ingredients);
    }

    /** @return array{language: string, title: string, tags: list<string>, steps: list<string>, notes: ?string, ingredients: list<array{rawText: string, name: ?string, prep: ?string, groupName: ?string}>} */
    public function toArray(): array
    {
        return [
            'language' => $this->language->value,
            'title' => $this->title,
            'tags' => $this->tags,
            'steps' => $this->steps,
            'notes' => $this->notes,
            'ingredients' => $this->ingredients,
        ];
    }

    /** Identifies this exact text: a translation remembers the hash of the text it was made from. */
    public function hash(): string
    {
        return hash('xxh128', json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /** A translation has to line up with the recipe: the same ingredient lines and steps, in order. */
    public function matches(self $other): bool
    {
        return \count($this->ingredients) === \count($other->ingredients) && \count($this->steps) === \count($other->steps);
    }

    /** Writes this text into the recipe, ingredient by ingredient (their amounts stay as they are). */
    public function applyTo(Recipe $recipe): void
    {
        $ingredients = $recipe->getIngredients();
        if (\count($ingredients) !== \count($this->ingredients)) {
            throw new \LogicException('The text has a different number of ingredients than the recipe.');
        }
        $recipe->setLanguage($this->language);
        $recipe->setTitle($this->title);
        $recipe->setTags($this->tags);
        $recipe->setSteps($this->steps);
        $recipe->setNotes($this->notes);
        foreach ($ingredients as $i => $ingredient) {
            $ingredient->setRawText($this->ingredients[$i]['rawText']);
            $ingredient->setName($this->ingredients[$i]['name']);
            $ingredient->setPrep($this->ingredients[$i]['prep']);
            $ingredient->setGroupName($this->ingredients[$i]['groupName']);
        }
    }

    private static function optional(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
