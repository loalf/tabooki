<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HouseholdRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A household shares one recipe library, meal plans and shopping lists.
 * Every household-owned table references it, so the app can host more than one later.
 */
#[ORM\Entity(repositoryClass: HouseholdRepository::class)]
class Household
{
    public const int DEFAULT_SERVINGS = 4;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,

        #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_SERVINGS])]
        private int $defaultServings = self::DEFAULT_SERVINGS,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDefaultServings(): int
    {
        return $this->defaultServings;
    }

    public function setDefaultServings(int $defaultServings): void
    {
        $this->defaultServings = $defaultServings;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
