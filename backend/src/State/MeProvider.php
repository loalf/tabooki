<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me;
use App\Entity\User;
use App\Extraction\RecipeExtractor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** @implements ProviderInterface<Me> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(private Security $security, private RecipeExtractor $extractor)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return Me::fromUser($user, [$this->extractor->textModel()]);
    }
}
