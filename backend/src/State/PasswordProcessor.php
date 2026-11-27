<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\ChangePasswordInput;
use App\Dto\ForgotPasswordInput;
use App\Dto\ResetPasswordInput;
use App\Entity\User;
use App\Security\PasswordReset;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/password/forgot, /api/password/reset and /api/me/password. Wrong current passwords
 * and wrong links are rate limited, on top of the 5 emails an hour per address and per IP.
 *
 * @implements ProcessorInterface<ForgotPasswordInput|ResetPasswordInput|ChangePasswordInput, null>
 */
final readonly class PasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private PasswordReset $passwords,
        private Security $security,
        private RequestStack $requests,
        private RateLimiterFactoryInterface $passwordAttemptLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $ip = $this->requests->getMainRequest()?->getClientIp() ?? 'unknown';

        if ($data instanceof ForgotPasswordInput) {
            if (!$this->passwords->request($data->email, $ip)) {
                throw new TooManyRequestsHttpException(3600, 'Too many requests. Try again later.');
            }

            return null;
        }

        if ($data instanceof ResetPasswordInput) {
            $this->attempt('ip:'.$ip);
            $user = $this->passwords->userFor($data->token)
                ?? throw self::invalid($data, 'token', 'This link is not valid any more: it was already used, or it expired. Ask for a new one.', $data->token);
            $this->passwords->setPassword($user, $data->password);

            return null;
        }

        // ChangePasswordInput
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        $this->attempt('user:'.$user->getId()->toRfc4122());
        // Someone who signed up with Google has no current password to give.
        if ($user->hasPassword() && !$this->passwords->isCurrentPassword($user, $data->currentPassword)) {
            throw self::invalid($data, 'currentPassword', 'The current password is not correct.', '');
        }
        $this->passwords->setPassword($user, $data->newPassword);
        // The session was tied to the old password: sign this device in again (with a fresh
        // remember-me cookie); the others are signed out.
        $this->security->login($user, 'json_login', 'main', [new RememberMeBadge()]);

        return null;
    }

    /** Counts a guess (wrong or right): 10 per 15 minutes. */
    private function attempt(string $key): void
    {
        $limit = $this->passwordAttemptLimiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many attempts. Try again later.');
        }
    }

    private static function invalid(object $root, string $property, string $message, string $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $root, $property, $value),
        ]));
    }
}
