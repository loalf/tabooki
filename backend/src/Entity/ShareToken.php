<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\State\CreateShareTokenProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A personal token that lets one device save links without a session: the iPhone Shortcut
 * sends it to POST /api/share (ShareController). Only its SHA-256 hash is stored; the token
 * itself is shown once, when created. It can do nothing else.
 */
#[ORM\Entity]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/share-tokens', order: ['createdAt' => 'DESC'], paginationEnabled: false),
        new Post(
            uriTemplate: '/share-tokens',
            normalizationContext: ['groups' => ['share_token:read', 'share_token:created']],
            processor: CreateShareTokenProcessor::class,
        ),
        new Delete(uriTemplate: '/share-tokens/{id}'),
    ],
    normalizationContext: ['groups' => ['share_token:read']],
    denormalizationContext: ['groups' => ['share_token:write']],
)]
class ShareToken
{
    public const string PREFIX = 'tbk_';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['share_token:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['share_token:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['share_token:read'])]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** Only set on the object just created, so the response can show it once. */
    private ?string $plainToken = null;

    public function __construct(
        /** Which device it's for, e.g. "iPhone de Belén". */
        #[ORM\Column(length: 60)]
        #[Groups(['share_token:read', 'share_token:write'])]
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        private string $name = '',
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Gives the token to a user and generates its secret (shown once, see getToken()). */
    public function issueTo(User $user): void
    {
        $this->user = $user;
        $this->plainToken = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->tokenHash = self::hash($this->plainToken);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    #[Groups(['share_token:created'])]
    #[ApiProperty(description: 'The secret, only in the response that creates the token.')]
    public function getToken(): ?string
    {
        return $this->plainToken;
    }
}
