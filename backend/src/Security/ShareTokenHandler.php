<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ShareToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/** "Authorization: Bearer tbk_…" on POST /api/share: the share firewall in security.yaml. */
final readonly class ShareTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = str_starts_with($accessToken, ShareToken::PREFIX)
            ? $this->entityManager->getRepository(ShareToken::class)->findOneBy(['tokenHash' => ShareToken::hash($accessToken)])
            : null;
        if (null === $token) {
            throw new BadCredentialsException('Invalid share token.');
        }

        $token->markUsed();
        $this->entityManager->flush();
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
