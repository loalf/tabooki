<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** A line of a shopping list, by its key; `checked` for check/uncheck. */
final class ShoppingCheckInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $key = '';

    public bool $checked = true;
}
