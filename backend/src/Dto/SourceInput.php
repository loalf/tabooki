<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /api/sources: just the link, as pasted or shared. */
final class SourceInput
{
    #[Groups(['source:create'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    public string $url = '';
}
