<?php

declare(strict_types=1);

namespace App\Taste;

use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Enum\MealType;

/**
 * What a household likes, as rules the planner applies by itself (docs/PLAN.md §6.3). A small,
 * typed vocabulary on purpose: the model compiling the questionnaire can only answer with it, and
 * the review screen shows one line per rule.
 */
final readonly class TasteRules
{
    public const int VERSION = 1;

    private const array GROUPS = ['red_meat', 'pork', 'poultry', 'processed_meat', 'fish', 'shellfish', 'animal_stock', 'egg', 'dairy', 'cheese', 'gluten', 'tree_nuts', 'peanuts', 'soy', 'sesame', 'alcohol'];
    private const array STRENGTH = ['type' => 'string', 'enum' => ['never', 'avoid']];
    private const array HEAVINESS = ['type' => ['string', 'null'], 'enum' => ['light', 'medium', 'hearty', null]];

    /** For OpenAPI (constant, for attributes): the shape of toArray(). */
    public const array API_SCHEMA = [
        'type' => 'object',
        'required' => ['version', 'diet', 'excludeGroups', 'excludeIngredients', 'weekly', 'mealHeaviness', 'prefer', 'maxSpice'],
        'properties' => [
            'version' => ['type' => 'integer'],
            'diet' => ['type' => ['string', 'null'], 'enum' => ['vegan', 'vegetarian', 'pescatarian', null]],
            'excludeGroups' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['group', 'strength'],
                'properties' => ['group' => ['type' => 'string', 'enum' => self::GROUPS], 'strength' => self::STRENGTH],
            ]],
            'excludeIngredients' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['keys', 'name', 'strength'],
                'properties' => ['keys' => ['type' => 'array', 'items' => ['type' => 'string']], 'name' => Names::API_SCHEMA, 'strength' => self::STRENGTH],
            ]],
            'weekly' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['groups', 'max', 'min'],
                'properties' => [
                    'groups' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::GROUPS]],
                    'max' => ['type' => ['integer', 'null']],
                    'min' => ['type' => ['integer', 'null']],
                ],
            ]],
            'mealHeaviness' => [
                'type' => 'object',
                'required' => ['lunch', 'dinner'],
                'properties' => ['lunch' => self::HEAVINESS, 'dinner' => self::HEAVINESS],
            ],
            'prefer' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['tags', 'name', 'weight'],
                'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'name' => Names::API_SCHEMA, 'weight' => ['type' => 'string', 'enum' => ['more', 'less']]],
            ]],
            'maxSpice' => ['type' => ['integer', 'null']],
        ],
    ];

    /**
     * @param list<ExcludedGroup>      $excludeGroups
     * @param list<ExcludedIngredient> $excludeIngredients
     * @param list<WeeklyLimit>        $weekly
     * @param list<Preference>         $prefer
     */
    public function __construct(
        public ?Diet $diet = null,
        public array $excludeGroups = [],
        public array $excludeIngredients = [],
        public array $weekly = [],
        public ?Heaviness $lunch = null,
        public ?Heaviness $dinner = null,
        public array $prefer = [],
        public ?int $maxSpice = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === self::none()->toArray();
    }

    public function heavinessFor(MealType $slot): ?Heaviness
    {
        return MealType::Lunch === $slot ? $this->lunch : $this->dinner;
    }

    /**
     * Reads rules stored or sent by a client or the model, keeping what is valid and dropping the
     * rest (an unknown group, a limit with no number). A group excluded twice keeps the firmer one.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $list = static fn (string $key): array => \is_array($data[$key] ?? null) ? array_values($data[$key]) : [];

        $groups = [];
        foreach (array_filter(array_map(ExcludedGroup::fromArray(...), $list('excludeGroups'))) as $rule) {
            $kept = $groups[$rule->group->value] ?? null;
            if (null === $kept || Strength::Never === $rule->strength) {
                $groups[$rule->group->value] = $rule;
            }
        }
        $meals = \is_array($data['mealHeaviness'] ?? null) ? $data['mealHeaviness'] : [];
        $heaviness = static fn (mixed $value): ?Heaviness => \is_string($value) ? Heaviness::tryFrom($value) : null;
        $spice = $data['maxSpice'] ?? null;

        return new self(
            diet: \is_string($data['diet'] ?? null) ? Diet::tryFrom($data['diet']) : null,
            excludeGroups: array_values($groups),
            excludeIngredients: \array_slice(array_values(array_filter(array_map(ExcludedIngredient::fromArray(...), $list('excludeIngredients')))), 0, 20),
            weekly: \array_slice(array_values(array_filter(array_map(WeeklyLimit::fromArray(...), $list('weekly')))), 0, 5),
            lunch: $heaviness($meals['lunch'] ?? null),
            dinner: $heaviness($meals['dinner'] ?? null),
            prefer: \array_slice(array_values(array_filter(array_map(Preference::fromArray(...), $list('prefer')))), 0, 10),
            maxSpice: \is_int($spice) && $spice >= 0 && $spice < 2 ? $spice : null,
        );
    }

    /**
     * @return array{version: int, diet: ?string, excludeGroups: list<array{group: string, strength: string}>,
     *     excludeIngredients: list<array{keys: list<string>, name: array{es: string, en: string}, strength: string}>,
     *     weekly: list<array{groups: list<string>, max: ?int, min: ?int}>, mealHeaviness: array{lunch: ?string, dinner: ?string},
     *     prefer: list<array{tags: list<string>, name: array{es: string, en: string}, weight: string}>, maxSpice: ?int}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'diet' => $this->diet?->value,
            'excludeGroups' => array_map(static fn (ExcludedGroup $r): array => $r->toArray(), $this->excludeGroups),
            'excludeIngredients' => array_map(static fn (ExcludedIngredient $r): array => $r->toArray(), $this->excludeIngredients),
            'weekly' => array_map(static fn (WeeklyLimit $r): array => $r->toArray(), $this->weekly),
            'mealHeaviness' => ['lunch' => $this->lunch?->value, 'dinner' => $this->dinner?->value],
            'prefer' => array_map(static fn (Preference $r): array => $r->toArray(), $this->prefer),
            'maxSpice' => $this->maxSpice,
        ];
    }

    /**
     * What the questionnaire's tappable answers say, without any model: the starting point the
     * model refines with the free text and the answers it can't map by itself (TasteCompiler).
     *
     * @param array<mixed> $answers see frontend/src/api/taste.ts (TasteAnswers)
     */
    public static function fromAnswers(array $answers): self
    {
        $diet = \is_string($answers['diet'] ?? null) ? Diet::tryFrom($answers['diet']) : null;

        $groups = [];
        foreach (\is_array($answers['avoid'] ?? null) ? $answers['avoid'] : [] as $item) {
            $group = \is_array($item) && \is_string($item['item'] ?? null) ? FoodGroup::tryFrom($item['item']) : null;
            $strength = \is_array($item) ? Strength::read($item['strength'] ?? null) : null;
            if (null !== $group && null !== $strength) {
                $groups[] = ['group' => $group->value, 'strength' => $strength->value];
            }
        }

        $weekly = [];
        $max = $answers['animalProteinMax'] ?? null;
        if (\is_int($max) && $max > 0) {
            $counted = FoodGroup::animalProtein();
            if (true === ($answers['eggsAndDairyCount'] ?? false)) {
                $counted = [...$counted, FoodGroup::Egg, FoodGroup::Dairy, FoodGroup::Cheese];
            }
            $weekly[] = ['groups' => array_map(static fn (FoodGroup $g): string => $g->value, $counted), 'max' => $max, 'min' => null];
        }
        $fish = $answers['fishMin'] ?? null;
        if (\is_int($fish) && $fish > 0) {
            $weekly[] = ['groups' => [FoodGroup::Fish->value, FoodGroup::Shellfish->value], 'max' => null, 'min' => $fish];
        }

        $meals = match ($answers['meals'] ?? null) {
            'heavyLunch' => ['lunch' => Heaviness::Hearty->value, 'dinner' => Heaviness::Light->value],
            'heavyDinner' => ['lunch' => Heaviness::Light->value, 'dinner' => Heaviness::Hearty->value],
            default => [],
        };

        return self::fromArray([
            'diet' => $diet?->value,
            'excludeGroups' => $groups,
            'weekly' => $weekly,
            'mealHeaviness' => $meals,
            'maxSpice' => match ($answers['spice'] ?? null) {
                'none' => 0,
                'mild' => 1,
                default => null,
            },
        ]);
    }

    /** @return array<string, mixed> JSON Schema of the rules, for strict tool use (every property required, no extra ones) */
    public static function schema(): array
    {
        $strength = ['type' => 'string', 'enum' => array_map(static fn (Strength $s): string => $s->value, Strength::cases())];
        $group = ['type' => 'string', 'enum' => FoodGroup::values()];
        // Strict tool use takes an enum only on a single type: "or null" goes in anyOf.
        $nullable = static fn (array $schema, string $description = ''): array => ['anyOf' => [$schema, ['type' => 'null']]] + ('' === $description ? [] : ['description' => $description]);
        $heaviness = $nullable(['type' => 'string', 'enum' => array_map(static fn (Heaviness $h): string => $h->value, Heaviness::cases())]);
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];

        return $object([
            'diet' => $nullable(['type' => 'string', 'enum' => array_map(static fn (Diet $d): string => $d->value, Diet::cases())], 'null: eats anything.'),
            'excludeGroups' => ['type' => 'array', 'items' => $object(['group' => $group, 'strength' => $strength])],
            'excludeIngredients' => [
                'type' => 'array',
                'description' => 'Ingredients that are no food group (coriander, mushrooms, olives).',
                'items' => $object([
                    'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Canonical English snake_case keys: the household\'s own where they exist, plus the usual ones for that ingredient.'],
                    'name' => Names::schema(),
                    'strength' => $strength,
                ]),
            ],
            'weekly' => [
                'type' => 'array',
                'description' => 'Meals a week whose dish needs any of these groups: at most max, at least min (null for no bound).',
                'items' => $object([
                    'groups' => ['type' => 'array', 'items' => $group],
                    'max' => ['type' => ['integer', 'null']],
                    'min' => ['type' => ['integer', 'null']],
                ]),
            ],
            'mealHeaviness' => $object(['lunch' => $heaviness, 'dinner' => $heaviness]),
            'prefer' => [
                'type' => 'array',
                'items' => $object([
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'English recipe tags: the household\'s own where they exist.'],
                    'name' => Names::schema(),
                    'weight' => ['type' => 'string', 'enum' => ['more', 'less']],
                ]),
            ],
            'maxSpice' => $nullable(['type' => 'integer', 'enum' => [0, 1]], '0: nothing spicy, 1: mild at most, null: any.'),
        ]);
    }
}
