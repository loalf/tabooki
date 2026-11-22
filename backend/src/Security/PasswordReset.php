<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Passwords: "forgot password" links by email, and setting a new password (from a link or from
 * Settings). A new password signs out every other device: sessions and remember-me cookies are
 * tied to the password hash. The person is told by email whenever their password changes.
 */
final readonly class PasswordReset
{
    public const int MIN_LENGTH = 8;
    public const string LIFETIME = '1 hour';

    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private RateLimiterFactoryInterface $passwordForgotLimiter,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $appUrl,
    ) {
    }

    /**
     * Emails a reset link if the address belongs to someone. Says nothing either way (the caller
     * answers the same), so the form can't be used to find out who has an account.
     *
     * @return bool false when rate limited (per address and per IP)
     */
    public function request(string $email, string $ip): bool
    {
        $email = mb_strtolower(trim($email));
        foreach (['email:'.$email, 'ip:'.$ip] as $key) {
            if (!$this->passwordForgotLimiter->create($key)->consume()->isAccepted()) {
                return false;
            }
        }

        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            return true;
        }

        $this->forget($user);
        $selector = bin2hex(random_bytes(12));
        $verifier = bin2hex(random_bytes(24));
        $this->entityManager->persist(new PasswordResetToken($user, $selector, hash('sha256', $verifier), new \DateTimeImmutable('+'.self::LIFETIME)));
        $this->entityManager->flush();

        $link = $this->url('/reset-password?token='.$selector.'.'.$verifier);
        $text = self::TEXT[$this->locale($user)];
        $this->send($user, $text['reset_subject'], \sprintf($text['reset_body'], $user->getDisplayName(), $link));

        return true;
    }

    /** The user a link is for, or null if it's wrong, used or expired. */
    public function userFor(string $token): ?User
    {
        $parts = explode('.', $token, 2);
        if (2 !== \count($parts) || 1 !== preg_match('/^[0-9a-f]{24}$/', $parts[0])) {
            return null;
        }
        $row = $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy(['selector' => $parts[0]]);

        return null !== $row && $row->matches($parts[1], new \DateTimeImmutable()) ? $row->getUser() : null;
    }

    /** Sets the password, voids every reset link of the user and tells them by email. */
    public function setPassword(User $user, #[\SensitiveParameter] string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->forget($user);
        $this->entityManager->flush();

        $text = self::TEXT[$this->locale($user)];
        $this->send($user, $text['changed_subject'], \sprintf($text['changed_body'], $user->getDisplayName(), $this->url('/forgot-password')));
    }

    public function isCurrentPassword(User $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->hasher->isPasswordValid($user, $plainPassword);
    }

    /** Removes the user's links, and any expired ones while at it. */
    private function forget(User $user): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(PasswordResetToken::class, 't')
            ->where('t.user = :user OR t.expiresAt < :now')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->execute();
    }

    private function send(User $user, string $subject, string $body): void
    {
        $email = new Email()
            ->to(new Address($user->getEmail(), $user->getDisplayName()))
            ->subject($subject)
            ->text($body);
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Only when sending synchronously; queued emails fail (and retry) in the worker.
            $this->logger->error('Could not send "{subject}" to a user: {error}', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }

    private function url(string $path): string
    {
        // DEFAULT_URI is e.g. https://tabooki.xyz:443; links read better without the default port.
        return rtrim((string) preg_replace('#^(https://[^/:]+):443(?=/|$)#', '$1', $this->appUrl), '/').$path;
    }

    /** @return 'es'|'en' */
    private function locale(User $user): string
    {
        return 'en' === $user->getLocale() ? 'en' : 'es';
    }

    private const array TEXT = [
        'es' => [
            'reset_subject' => 'Cambia tu contraseña de Tabooki',
            'reset_body' => "Hola, %s:\n\nAlguien (seguramente tú) ha pedido cambiar la contraseña de tu cuenta de Tabooki. Para elegir una nueva, abre este enlace:\n\n%s\n\nEl enlace sirve una vez y caduca en una hora. Si no lo has pedido tú, no hagas nada: tu contraseña sigue siendo la misma.\n\nTabooki",
            'changed_subject' => 'Tu contraseña de Tabooki ha cambiado',
            'changed_body' => "Hola, %s:\n\nLa contraseña de tu cuenta de Tabooki acaba de cambiar, y se ha cerrado la sesión en tus otros dispositivos.\n\nSi no has sido tú, pide un enlace para cambiarla cuanto antes:\n%s\n\nTabooki",
        ],
        'en' => [
            'reset_subject' => 'Reset your Tabooki password',
            'reset_body' => "Hi %s,\n\nSomeone (probably you) asked to reset the password of your Tabooki account. To choose a new one, open this link:\n\n%s\n\nThe link works once and expires in an hour. If you didn't ask for it, do nothing: your password stays the same.\n\nTabooki",
            'changed_subject' => 'Your Tabooki password has changed',
            'changed_body' => "Hi %s,\n\nThe password of your Tabooki account has just changed, and your other devices have been signed out.\n\nIf this wasn't you, ask for a reset link straight away:\n%s\n\nTabooki",
        ],
    ];
}
