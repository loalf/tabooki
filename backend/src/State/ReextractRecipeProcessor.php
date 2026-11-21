<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Recipe;
use App\Enum\SourceStatus;
use App\Message\ExtractSource;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/recipes/{id}/extract: extract the recipe again from its link (with the current model
 * and prompt), rewriting it in place. Follow it through `source.status`.
 *
 * @implements ProcessorInterface<null, Recipe>
 */
final readonly class ReextractRecipeProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Recipe
    {
        $id = $uriVariables['id'] ?? null;
        // API Platform already turns {id} into a Uuid; be lenient with plain strings too.
        $uuid = $id instanceof Uuid ? $id : (\is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null);
        $recipe = null === $uuid ? null : $this->entityManager->find(Recipe::class, $uuid);
        if (null === $recipe || !$recipe->getHousehold()->getId()->equals($this->current->household()->getId())) {
            throw new NotFoundHttpException('Not Found');
        }
        $source = $recipe->getSource() ?? throw new ConflictHttpException('This recipe has no link to extract it from.');

        if (!\in_array($source->getStatus(), [SourceStatus::Pending, SourceStatus::Extracting], true)) {
            $source->setStatus(SourceStatus::Pending);
            $this->entityManager->flush();
            $this->bus->dispatch(new ExtractSource($source->getId()->toRfc4122(), $recipe->getId()->toRfc4122()));
        }

        return $recipe;
    }
}
