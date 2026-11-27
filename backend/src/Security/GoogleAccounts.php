<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * The Tabooki account of someone who signed in with Google. Anyone can sign up this way: someone
 * new gets an account in a household of their own. Someone who already has an account (made with
 * `make user`) gets it, matched by email, and Google is linked to it from then on.
 */
final readonly class GoogleAccounts
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private RateLimiterFactoryInterface $googleSignupLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $locale for a new account, when the SPA didn't say
     *
     * @throws TooManyLoginAttemptsAuthenticationException when this IP made too many accounts today
     */
    public function userFor(GoogleIdentity $google, string $locale, string $ip): User
    {
        $user = $this->users->findOneByGoogleId($google->id);
        if (null !== $user) {
            return $user;
        }

        // Google verified the address (GoogleSignIn::finish), so it's theirs: link the account.
        $user = $this->users->findOneByEmail($google->email);
        if (null !== $user) {
            $user->setGoogleId($google->id);
            $this->entityManager->flush();

            return $user;
        }

        if (!$this->googleSignupLimiter->create('ip:'.$ip)->consume()->isAccepted()) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }

        $locale = $google->locale ?? $locale;
        $name = mb_substr('' !== $google->name ? $google->name : (string) strstr($google->email, '@', true), 0, 100);
        $firstName = '' !== $google->givenName ? $google->givenName : explode(' ', $name)[0];
        $household = new Household(mb_substr('en' === $locale ? $firstName."'s home" : 'Casa de '.$firstName, 0, 100));
        $user = new User($google->email, $name, $household, $locale);
        $user->setGoogleId($google->id);
        $this->entityManager->persist($household);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->logger->notice('New account from Google sign-in: {email}', ['email' => $google->email]);

        return $user;
    }
}
