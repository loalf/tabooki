<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TasteProfileView;
use App\Entity\TasteProfile;
use App\Repository\TasteProfileRepository;
use App\Security\CurrentHousehold;
use App\Taste\TasteImpact;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<TasteProfileView> */
final readonly class TasteProfileProvider implements ProviderInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private TasteProfileRepository $profiles,
        private TasteImpact $impact,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TasteProfileView
    {
        $profile = $this->profiles->forHousehold($this->current->household());
        if (null === $profile) {
            throw new NotFoundHttpException('The household hasn\'t said what it likes yet.');
        }

        return $this->view($profile);
    }

    public function view(TasteProfile $profile): TasteProfileView
    {
        $rules = $profile->getRules();
        $view = new TasteProfileView();
        $view->id = $profile->getId()->toRfc4122();
        $view->answers = $profile->getAnswers();
        $view->rules = $rules->toArray();
        $view->notes = $profile->getNotes();
        $view->summary = $profile->getSummary();
        $view->updatedBy = $profile->getUpdatedBy()?->getDisplayName();
        $view->updatedAt = $profile->getUpdatedAt();
        $view->impact = $this->impact->of($profile->getHousehold(), $rules, $profile->getNotes());

        return $view;
    }
}
