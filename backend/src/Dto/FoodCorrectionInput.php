<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Allergen;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** A food's figures fixed by hand: all of them at once, per 100 g as a recipe uses it. */
final class FoodCorrectionInput
{
    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 900)]
    public float $kcal = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    public float $protein = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    public float $fat = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    #[Assert\LessThanOrEqual(propertyPath: 'fat', message: 'Saturated fat can\'t be more than the fat.')]
    public float $saturatedFat = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    public float $carbs = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    #[Assert\LessThanOrEqual(propertyPath: 'carbs', message: 'Sugar can\'t be more than the carbohydrates.')]
    public float $sugar = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    public float $fiber = 0;

    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 100)]
    public float $salt = 0;

    /** @var list<string> Allergen values */
    #[Groups(['food:correct'])]
    #[Assert\All([new Assert\Choice(callback: [Allergen::class, 'values'])])]
    public array $allergens = [];

    /** 0 not spicy, 1 mild, 2 hot. */
    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0, max: 2)]
    public int $spice = 0;

    /** One piece as bought or counted (1 onion); null when it isn't counted. */
    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0.1, max: 20000)]
    public ?float $gramsPerPiece = null;

    /** Grams per millilitre; null when it's never measured by volume. */
    #[Groups(['food:correct'])]
    #[Assert\Range(min: 0.1, max: 5)]
    public ?float $density = null;
}
