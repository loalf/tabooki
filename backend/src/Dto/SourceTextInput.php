<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /api/sources/{id}/text: the recipe as the cook copied it (caption, comment, screenshot text). */
final class SourceTextInput
{
    #[Groups(['source:text'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 20000)]
    public string $text = '';
}
