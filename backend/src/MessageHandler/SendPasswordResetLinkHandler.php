<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPasswordResetLink;
use App\Security\PasswordReset;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendPasswordResetLinkHandler
{
    public function __construct(private PasswordReset $passwordReset)
    {
    }

    public function __invoke(SendPasswordResetLink $message): void
    {
        $this->passwordReset->sendLink($message->email);
    }
}
