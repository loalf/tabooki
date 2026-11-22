<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Email a reset link to this address, if it belongs to someone. Done in the worker so that the
 * "forgot password" request takes the same time whether or not the address has an account.
 */
final readonly class SendPasswordResetLink
{
    public function __construct(public string $email)
    {
    }
}
