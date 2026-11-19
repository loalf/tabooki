<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Something added to a shopping list by hand ("bin bags", "milk for breakfast"). */
#[ORM\Entity]
class ShoppingListItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'items')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ShoppingList $list,
        #[ORM\Column(length: 200)]
        private string $text,
    ) {
        $this->id = Uuid::v7();
        $this->text = mb_substr(trim($text), 0, 200);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function checkKey(): string
    {
        return 'item:'.$this->id->toRfc4122();
    }
}
