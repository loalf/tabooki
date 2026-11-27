<?php

declare(strict_types=1);

namespace App\Security;

/** Who signed in with Google (verified claims of the ID token), and what the SPA asked for. */
final readonly class GoogleIdentity
{
    public function __construct(
        /** Google's stable id for the account (`sub`): emails can change, this can't. */
        public string $id,
        /** Verified by Google, lowercased. */
        public string $email,
        public string $name,
        public string $givenName,
        /** The SPA path to land on. */
        public string $target,
        /** The SPA's language when they pressed the button, if it sent one. */
        public ?string $locale,
    ) {
    }
}
