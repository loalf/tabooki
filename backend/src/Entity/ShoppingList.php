<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What's stored about a week's shopping list: what's been checked off and the items added by
 * hand. The ingredients themselves are worked out from that week's plan whenever the list is
 * read (App\Shopping\ShoppingListBuilder), so they follow every change to the plan.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'shopping_list_household_week', columns: ['household_id', 'week_start'])]
class ShoppingList
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /** @var Collection<int, ShoppingListItem> */
    #[ORM\OneToMany(targetEntity: ShoppingListItem::class, mappedBy: 'list', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $items;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Household $household,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private \DateTimeImmutable $weekStart,
        /** @var list<string> keys of the lines checked off ("onion", "item:<id>") */
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $checked = [],
    ) {
        $this->id = Uuid::v7();
        $this->weekStart = MealPlan::mondayOf($weekStart);
        $this->items = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    /** @return list<string> */
    public function getChecked(): array
    {
        return $this->checked;
    }

    public function isChecked(string $key): bool
    {
        return \in_array($key, $this->checked, true);
    }

    /** @return list<ShoppingListItem> */
    public function getItems(): array
    {
        return array_values($this->items->toArray());
    }

    public function item(string $id): ?ShoppingListItem
    {
        foreach ($this->items as $item) {
            if ($item->getId()->toRfc4122() === $id) {
                return $item;
            }
        }

        return null;
    }

    public function addItem(string $text): ShoppingListItem
    {
        $item = new ShoppingListItem($this, $text);
        $this->items->add($item);

        return $item;
    }

    public function removeItem(ShoppingListItem $item): void
    {
        $this->items->removeElement($item);
    }
}
