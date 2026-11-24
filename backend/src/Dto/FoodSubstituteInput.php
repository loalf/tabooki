<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** One substitute: a food from the catalog (its key and names), or one typed by hand (a name, the key made from it). */
final class FoodSubstituteInput
{
    /** Canonical English key; null to make one from the name. */
    #[Assert\Length(max: 80)]
    public ?string $key = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $nameEs = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $nameEn = '';

    /** Grams of the substitute for each gram of the food. */
    #[Assert\Range(min: 0.2, max: 5)]
    public float $ratio = 1;

    /** How it changes the dish, or what to add; null when it hardly changes anything. */
    #[Assert\Length(max: 120)]
    public ?string $noteEs = null;

    #[Assert\Length(max: 120)]
    public ?string $noteEn = null;
}
