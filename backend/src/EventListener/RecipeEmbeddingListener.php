<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Recipe;
use App\Message\EmbedRecipe;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every recipe added or changed is embedded again in the background (search by meaning). Its
 * ingredients and facts all end up changing the recipe row (its search text, facts, nutrition),
 * so watching recipes is enough; the handler skips those whose digest didn't change.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class RecipeEmbeddingListener
{
    /** @var array<string, true> */
    private array $changed = [];

    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof Recipe) {
                $this->changed[$entity->getId()->toRfc4122()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $changed = array_keys($this->changed);
        $this->changed = [];
        foreach ($changed as $id) {
            $this->bus->dispatch(new EmbedRecipe($id));
        }
    }
}
