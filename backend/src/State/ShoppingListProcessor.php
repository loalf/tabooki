<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ShoppingListView;
use App\Dto\ShoppingCheckInput;
use App\Dto\ShoppingItemInput;
use App\Entity\Household;
use App\Entity\MealPlan;
use App\Entity\ShoppingList;
use App\Security\CurrentHousehold;
use App\Shopping\ShoppingListBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Checking lines off and adding items by hand. Checks are single SQL updates, so two people
 * ticking things off at once (or a phone catching up after being offline) don't undo each other.
 *
 * @implements ProcessorInterface<ShoppingCheckInput|ShoppingItemInput, ShoppingListView>
 */
final readonly class ShoppingListProcessor implements ProcessorInterface
{
    public function __construct(
        private ShoppingListBuilder $builder,
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
        private RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShoppingListView
    {
        $household = $this->current->household();
        $week = ShoppingListProvider::day($uriVariables['week'] ?? null) ?? throw new NotFoundHttpException('Weeks are YYYY-MM-DD.');
        $list = $this->list($household, $week);

        if ($data instanceof ShoppingCheckInput && 'shopping_list_check' === $operation->getName()) {
            $this->entityManager->getConnection()->executeStatement(
                $data->checked
                    ? 'UPDATE shopping_list SET checked = checked || to_jsonb(CAST(:key AS text)) WHERE id = :id AND NOT jsonb_exists(checked, :key)'
                    : 'UPDATE shopping_list SET checked = checked - CAST(:key AS text) WHERE id = :id',
                ['key' => $data->key, 'id' => $list->getId()->toRfc4122()],
            );
        } elseif ($data instanceof ShoppingItemInput) {
            $list->addItem($data->text);
            $this->entityManager->flush();
        } elseif ($data instanceof ShoppingCheckInput) {
            $item = $list->item(substr($data->key, \strlen('item:'))) ?? throw new NotFoundHttpException('No such item.');
            $list->removeItem($item);
            $this->entityManager->flush();
        }

        $this->entityManager->clear();
        $from = $this->requests->getCurrentRequest()?->query->get('from');

        return $this->builder->build($this->current->household(), $week, $this->current->user()->getLocale(), ShoppingListProvider::day($from));
    }

    /** The week's stored list, created on first use (concurrently safe). */
    private function list(Household $household, \DateTimeImmutable $week): ShoppingList
    {
        $monday = MealPlan::mondayOf($week);
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO shopping_list (id, household_id, week_start, checked) VALUES (:id, :household, :week, \'[]\')
             ON CONFLICT (household_id, week_start) DO NOTHING',
            ['id' => Uuid::v7()->toRfc4122(), 'household' => $household->getId()->toRfc4122(), 'week' => $monday->format('Y-m-d')],
        );

        return $this->entityManager->getRepository(ShoppingList::class)->findOneBy(['household' => $household, 'weekStart' => $monday])
            ?? throw new \LogicException('The shopping list should exist now.');
    }
}
