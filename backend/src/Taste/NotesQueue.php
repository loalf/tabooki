<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Household;
use App\Entity\Recipe;
use App\Entity\TasteProfile;
use App\Message\JudgeNotes;
use App\Message\SummarizeTaste;
use App\Repository\TasteProfileRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues the judging of recipes against the household's notes, and the summary of its likings, when there is something to do. */
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

    /** After the likings were saved: their summary written again, when they no longer have one. */
    public function likingsSaved(TasteProfile $profile): void
    {
        $this->notesSaved($profile->getHousehold(), $profile->getNotes());
        if ($profile->needsSummary()) {
            $this->bus->dispatch(new SummarizeTaste($profile->getHousehold()->getId()->toRfc4122()));
        }
    }
}
