<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Me;
use App\Entity\User;
use App\Extraction\RecipeExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Applies a validated PATCH /api/me to the signed-in user.
 *
 * @implements ProcessorInterface<Me, Me>
 */
final readonly class MeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private RecipeExtractor $extractor,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param Me $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $user->setDisplayName($data->displayName);
        $user->setLocale($data->locale);
        $this->entityManager->flush();

        return Me::fromUser($user, [$this->extractor->textModel(), $this->extractor->videoModel()]);
    }
}
