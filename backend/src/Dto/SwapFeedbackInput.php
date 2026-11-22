<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Why a meal was swapped: the recipe swapped out, the meal, and the reason. */
final class SwapFeedbackInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $recipe = '';

    #[Assert\Choice(choices: ['notToday', 'dislike', 'tooHeavy', 'tooLight', 'tooLong'])]
    public string $reason = 'notToday';

    /** For a dislike: "group", "ingredient" or "dish". */
    #[Assert\Choice(choices: ['group', 'ingredient', 'dish'])]
    public ?string $whatKind = null;

    /** The food group, or the ingredient's canonical key. */
    #[Assert\Length(max: 80)]
    public ?string $whatValue = null;

    #[Assert\Choice(choices: ['lunch', 'dinner'])]
    public string $slot = 'dinner';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $date = '';
}
