<?php

declare(strict_types=1);

namespace App\Dto;

use App\Security\PasswordReset;
use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordInput
{
    /** From the emailed link: "<selector>.<verifier>". */
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: PasswordReset::MIN_LENGTH, max: 4096)]
    public string $password = '';
}
