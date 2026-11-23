<?php

declare(strict_types=1);

namespace App\Food\Usda;

/**
 * A food of USDA FoodData Central: its id, description, dataset ("SR Legacy", "Foundation",
 * "Survey (FNDDS)", "Branded") and nutrients per 100 g, by USDA nutrient number ("208" kcal…).
 */
final readonly class UsdaFood
{
    /** @param array<array-key, array{value: float, unit: string}> $nutrients by USDA number (PHP makes "208" an int key) */
    public function __construct(
        public int $fdcId,
        public string $description,
        public string $dataType,
        public array $nutrients,
    ) {
    }

    /**
     * From a search result or an abridged food (their nutrient lists differ).
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['fdcId'] ?? null;
        $description = $data['description'] ?? null;
        if (!\is_int($id) || !\is_string($description)) {
            return null;
        }
        $nutrients = [];
        foreach (\is_array($data['foodNutrients'] ?? null) ? $data['foodNutrients'] : [] as $nutrient) {
            if (!\is_array($nutrient)) {
                continue;
            }
            $number = $nutrient['nutrientNumber'] ?? $nutrient['number'] ?? null;
            $value = $nutrient['value'] ?? $nutrient['amount'] ?? null;
            $unit = $nutrient['unitName'] ?? '';
            if (\is_string($number) && is_numeric($value) && \is_string($unit)) {
                $nutrients[$number] = ['value' => (float) $value, 'unit' => strtolower($unit)];
            }
        }

        return new self($id, trim($description), \is_string($data['dataType'] ?? null) ? $data['dataType'] : '', $nutrients);
    }

    /** @return array<string, mixed> what fromArray() reads back */
    public function toArray(): array
    {
        return [
            'fdcId' => $this->fdcId,
            'description' => $this->description,
            'dataType' => $this->dataType,
            'foodNutrients' => array_map(
                static fn (string $number, array $n): array => ['nutrientNumber' => $number, 'value' => $n['value'], 'unitName' => $n['unit']],
                array_keys($this->nutrients),
                $this->nutrients,
            ),
        ];
    }
}
