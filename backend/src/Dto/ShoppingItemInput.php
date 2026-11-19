<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ShoppingItemInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $text = '';
}
