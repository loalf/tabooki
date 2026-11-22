<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class TasteSuggestionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $key = '';
}
