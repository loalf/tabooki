<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\TasteSuggestionInput;
use App\Security\CurrentHousehold;
use App\Taste\SwapLearning;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<TasteSuggestionInput, null> */
final readonly class TasteSuggestionProcessor implements ProcessorInterface
{
    public function __construct(private CurrentHousehold $current, private SwapLearning $learning)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $household = $this->current->household();
        if ('taste_suggestion_dismiss' === $operation->getName()) {
            $this->learning->dismiss($household, $data->key);

            return null;
        }
        if (!$this->learning->accept($household, $data->key, $this->current->user())) {
            throw new NotFoundHttpException('No such suggestion.');
        }

        return null;
    }
}
