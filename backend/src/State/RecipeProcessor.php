<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Recipe;
use App\Food\FoodCatalog;
use App\Food\RecipeWeigher;
use App\Message\AnnotateRecipe;
use App\Message\TranslateRecipe;
use App\Taste\NotesQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves a recipe typed or edited by hand, then has it translated into the other language when
 * its text changed (see Recipe::keepOriginalIfUntouched() for edits made to the translation),
 * and its facts read again, and judged against the household's notes, when its title or
 * ingredients did. Ingredients new to the shared food catalog are added and described, and the
 * lines weighed (a weight typed in is kept).
 *
 * @implements ProcessorInterface<Recipe, mixed>
 */
final readonly class RecipeProcessor implements ProcessorInterface
{
    public function __construct(
        private HouseholdOwnedProcessor $persist,
        private MessageBusInterface $bus,
        private NotesQueue $notes,
        private FoodCatalog $foods,
        private RecipeWeigher $weigher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $data->keepOriginalIfUntouched();
        $result = $this->persist->process($data, $operation, $uriVariables, $context);
        if ($data->needsTranslation()) {
            $this->bus->dispatch(new TranslateRecipe($data->getId()->toRfc4122()));
        }
        if ($data->needsFacts()) {
            $this->bus->dispatch(new AnnotateRecipe($data->getId()->toRfc4122()));
        }
        $this->notes->recipeChanged($data);
        $this->foods->recipeChanged($data);
        $this->weigher->weigh($data);
        $this->entityManager->flush();

        return $result;
    }
}
