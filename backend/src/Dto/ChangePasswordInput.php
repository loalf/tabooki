<?php

declare(strict_types=1);

namespace App\Dto;

use App\Security\PasswordReset;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordInput
{
    #[Groups(['me:password'])]
    #[Assert\NotBlank]
    public string $currentPassword = '';

    #[Groups(['me:password'])]
    #[Assert\NotBlank]
    #[Assert\Length(min: PasswordReset::MIN_LENGTH, max: 4096)]
    #[Assert\NotEqualTo(propertyPath: 'currentPassword', message: 'The new password must be different from the current one.')]
    public string $newPassword = '';
}
