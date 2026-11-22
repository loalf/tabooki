<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\ForgotPasswordInput;
use App\Dto\ResetPasswordInput;
use App\State\PasswordProcessor;

/**
 * Forgotten passwords, for people who aren't signed in (changing it while signed in is
 * POST /api/me/password). See App\Security\PasswordReset.
 */
#[ApiResource(
    shortName: 'Password',
    operations: [
        new Post(
            uriTemplate: '/password/forgot',
            status: 202,
            description: 'Email a link to choose a new password. Answers the same whether or not the address has an account.',
            input: ForgotPasswordInput::class,
            output: false,
            read: false,
            processor: PasswordProcessor::class,
            name: 'password_forgot',
        ),
        new Post(
            uriTemplate: '/password/reset',
            status: 204,
            description: 'Choose a new password with the token from the emailed link (once, within an hour). Signs out every device.',
            input: ResetPasswordInput::class,
            output: false,
            read: false,
            processor: PasswordProcessor::class,
            name: 'password_reset',
        ),
    ],
)]
final class Password
{
}
