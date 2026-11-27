<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** They pressed Cancel on Google's page: back to the sign-in page, with nothing to explain. */
final class GoogleSignInCancelled extends AuthenticationException
{
}
