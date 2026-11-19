<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ShoppingListView;
use App\Security\CurrentHousehold;
use App\Shopping\ShoppingListBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<ShoppingListView> */
final readonly class ShoppingListProvider implements ProviderInterface
{
    public function __construct(
        private ShoppingListBuilder $builder,
        private CurrentHousehold $current,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ShoppingListView
    {
        $from = $this->requests->getCurrentRequest()?->query->get('from');

        return $this->builder->build(
            $this->current->household(),
            self::day($uriVariables['week'] ?? null) ?? throw new NotFoundHttpException('Weeks are YYYY-MM-DD.'),
            $this->current->user()->getLocale(),
            self::day($from),
        );
    }

    public static function day(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null) : null;
    }
}
