<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A "forgot password" link, valid once and for a short time (App\Security\PasswordReset). The
 * link carries "<selector>.<verifier>": the selector finds the row, the verifier is only stored as
 * a SHA-256 hash, so a database leak doesn't hand out working links.
 */
#[ORM\Entity]
#[ORM\Index(name: 'password_reset_token_expires', columns: ['expires_at'])]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 24, unique: true)]
        private string $selector,
        #[ORM\Column(length: 64)]
        private string $verifierHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function matches(string $verifier, \DateTimeImmutable $now): bool
    {
        return $now < $this->expiresAt && hash_equals($this->verifierHash, hash('sha256', $verifier));
    }
}
