<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ShareToken;
use App\Security\CurrentHousehold;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** @implements ProcessorInterface<ShareToken, ShareToken> */
final readonly class CreateShareTokenProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<ShareToken, ShareToken> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persist,
        private CurrentHousehold $current,
    ) {
    }

    /** @param ShareToken $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShareToken
    {
        $data->setName($data->getName());
        $data->issueTo($this->current->user());

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
