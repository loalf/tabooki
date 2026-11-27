<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Signs in whoever Google sends back to /api/auth/google/callback (see GoogleSignIn), then lands
 * the browser in the SPA: where they were going, or back on /login with what went wrong.
 */
final class GoogleAuthenticator extends AbstractAuthenticator
{
    private const string TARGET = '_google_target';

    public function __construct(
        private readonly GoogleSignIn $google,
        private readonly GoogleAccounts $accounts,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return '/api/auth/google/callback' === $request->getPathInfo() && $request->isMethod('GET') && $this->google->isEnabled();
    }

    public function authenticate(Request $request): Passport
    {
        $identity = $this->google->finish($request);
        $request->attributes->set(self::TARGET, $identity->target);
        $locale = $request->getPreferredLanguage(User::LOCALES) ?? User::LOCALES[0];
        $user = $this->accounts->userFor($identity, $locale, $request->getClientIp() ?? 'unknown');

        // Stays signed in like a password sign-in (always_remember_me in security.yaml).
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user), [new RememberMeBadge()]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $target = $request->attributes->get(self::TARGET);

        return new RedirectResponse(\is_string($target) ? $target : '/');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof GoogleSignInCancelled) {
            $this->logger->warning('Google sign-in failed: {reason}', ['reason' => $exception->getMessageKey()]);
        }

        return new RedirectResponse(match (true) {
            $exception instanceof GoogleSignInCancelled => '/login',
            $exception instanceof TooManyLoginAttemptsAuthenticationException => '/login?error=google-limit',
            default => '/login?error=google',
        });
    }
}
