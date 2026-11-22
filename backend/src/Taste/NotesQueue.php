<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Household;
use App\Entity\Recipe;
use App\Message\JudgeNotes;
use App\Repository\TasteProfileRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues the judging of recipes against the household's notes when there is something to judge. */
final readonly class NotesQueue
{
    public function __construct(
        private TasteProfileRepository $profiles,
        private MessageBusInterface $bus,
    ) {
    }

    /** After a recipe was added or changed: judged again if the household has notes and these don't apply any more. */
    public function recipeChanged(Recipe $recipe): void
    {
        $hash = Taste::notesHash($this->profiles->forHousehold($recipe->getHousehold())?->getNotes());
        if (null !== $hash && $recipe->needsNotesJudgement($hash)) {
            $this->bus->dispatch(new JudgeNotes($recipe->getHousehold()->getId()->toRfc4122()));
        }
    }

    /** After the notes were saved: the whole library, or what isn't judged against them yet. */
    public function notesSaved(Household $household, ?string $notes): void
    {
        if (null !== Taste::notesHash($notes)) {
            $this->bus->dispatch(new JudgeNotes($household->getId()->toRfc4122()));
        }
    }
}
