<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\HouseholdOwned;
use App\Security\CurrentHousehold;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Saves household-owned resources, assigning new ones to the signed-in user's household.
 *
 * @implements ProcessorInterface<HouseholdOwned, mixed>
 */
final readonly class HouseholdOwnedProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<HouseholdOwned, mixed> $persistProcessor */
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private ProcessorInterface $persistProcessor,
        private CurrentHousehold $current,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($operation instanceof Post) {
            $data->assignHousehold($this->current->household());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
