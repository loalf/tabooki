<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\MealPlanEntryAction;
use App\Dto\MealPlanEntryRestore;
use App\Dto\MealPlanSlotInput;
use App\Dto\MealPlanTweakInput;
use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use App\Entity\Recipe;
use App\Enum\MealType;
use App\Planning\Planner;
use App\Repository\MealPlanRepository;
use App\Security\CurrentHousehold;
use App\Taste\WeekTweak;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Everything done to a planned week after creating it (see the operations on MealPlan). Each
 * answers with the whole plan.
 *
 * @implements ProcessorInterface<MealPlanSlotInput|MealPlanEntryAction|MealPlanEntryRestore|MealPlanTweakInput|null, MealPlan>
 */
final readonly class MealPlanActionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private MealPlanRepository $plans,
        private Planner $planner,
        private WeekTweak $tweak,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MealPlan
    {
        $plan = $this->plan($uriVariables['id'] ?? null);

        match ($operation->getName()) {
            'meal_plan_regenerate' => $this->planner->fill($plan),
            'meal_plan_tweak' => $data instanceof MealPlanTweakInput ? $this->tweak($plan, $data) : null,
            'meal_plan_add_entry' => $data instanceof MealPlanSlotInput ? $this->add($plan, $data) : null,
            'meal_plan_swap_entry' => $data instanceof MealPlanEntryAction ? $this->planner->fillOne($plan, $this->entry($plan, $data), swap: true) : null,
            'meal_plan_update_entry' => $data instanceof MealPlanEntryAction ? $this->update($plan, $data) : null,
            'meal_plan_remove_entry' => $data instanceof MealPlanEntryAction ? $plan->removeEntry($this->entry($plan, $data)) : null,
            'meal_plan_restore_entry' => $data instanceof MealPlanEntryRestore ? $this->restore($plan, $data) : null,
            default => throw new \LogicException('Unknown meal plan operation '.$operation->getName()),
        };
        $this->entityManager->flush();

        return $plan;
    }

    private function tweak(MealPlan $plan, MealPlanTweakInput $input): void
    {
        $this->tweak->apply($plan, $input->tweak);
        $this->planner->fill($plan);
    }

    private function add(MealPlan $plan, MealPlanSlotInput $input): void
    {
        $date = CreateMealPlanProcessor::date($input->date);
        if (!$plan->contains($date)) {
            throw new UnprocessableEntityHttpException(\sprintf('%s is not in the week of %s.', $input->date, $plan->getWeekStart()));
        }
        $slot = MealType::from($input->slot);
        if (null !== $plan->entryAt($date, $slot)) {
            throw new ConflictHttpException('That meal is already planned.');
        }

        $entry = new MealPlanEntry($plan, $date, $slot, $input->servings ?? $plan->getHousehold()->getDefaultServings());
        $plan->addEntry($entry);
        $this->planner->fillOne($plan, $entry);
    }

    private function update(MealPlan $plan, MealPlanEntryAction $input): void
    {
        $entry = $this->entry($plan, $input);
        if (null !== $input->locked) {
            $entry->setLocked($input->locked);
        }
        if (null !== $input->servings) {
            $entry->setServings($input->servings);
        }
        if (null !== $input->recipe) {
            $this->planner->choose($plan, $entry, $this->recipe($plan, $input->recipe));
        }
        // After choosing: the rating is about the recipe that was eaten.
        if (null !== $input->rating) {
            $entry->rate($input->rating);
        }
        if ($input->setsCooked()) {
            $entry->setCooked($input->cooked);
            // Not made: its leftovers still to come can't be leftovers, they're that dish to cook then.
            if (false === $input->cooked) {
                $today = new \DateTimeImmutable('today');
                foreach ($plan->getEntries() as $other) {
                    if ($other->getLeftoverOf() === $entry && $other->getDateValue() >= $today) {
                        $other->setLeftoverOf(null);
                    }
                }
            }
        }
        if (null !== $input->leftoverOf) {
            $source = $plan->entry($input->leftoverOf) ?? throw new UnprocessableEntityHttpException('That meal is not in this plan.');
            if ($source === $entry || null === $entry->getRecipe() || $source->getRecipe() !== $entry->getRecipe() || $source->sortKey() >= $entry->sortKey()) {
                throw new UnprocessableEntityHttpException('Leftovers are the same dish, eaten after it.');
            }
            $entry->setLeftoverOf($source);
        }
    }

    /** The undo of "remove": the same meal (same id) back in its place, without planning anything. */
    private function restore(MealPlan $plan, MealPlanEntryRestore $input): void
    {
        $date = CreateMealPlanProcessor::date($input->date);
        if (!$plan->contains($date)) {
            throw new UnprocessableEntityHttpException(\sprintf('%s is not in the week of %s.', $input->date, $plan->getWeekStart()));
        }
        $slot = MealType::from($input->slot);
        if (null !== $plan->entry($input->entry) || null !== $plan->entryAt($date, $slot)) {
            throw new ConflictHttpException('That meal is already planned.');
        }

        $entry = MealPlanEntry::restored(Uuid::fromString($input->entry), $plan, $date, $slot, $input->servings);
        $entry->setRecipe(null === $input->recipe ? null : $this->recipe($plan, $input->recipe));
        $entry->setReasons(array_values(array_filter($input->reasons, static fn (array $reason): bool => \is_string($reason['code'] ?? null))));
        $entry->setLocked($input->locked);
        $entry->restoreRating($input->rating);
        $entry->setCooked($input->cooked);
        $plan->addEntry($entry);

        // Leftovers are the same dish: only relinked while that's still so.
        $source = null === $input->leftoverOf ? null : $plan->entry($input->leftoverOf);
        if (null !== $source && null !== $entry->getRecipe() && $source->getRecipe() === $entry->getRecipe()) {
            $entry->setLeftoverOf($source);
        }
        foreach ($input->leftovers as $id) {
            $leftovers = $plan->entry($id);
            if (null !== $leftovers && null === $leftovers->getLeftoverOf() && null !== $entry->getRecipe() && $leftovers->getRecipe() === $entry->getRecipe()) {
                $leftovers->setLeftoverOf($entry);
            }
        }
    }

    private function recipe(MealPlan $plan, string $id): Recipe
    {
        $recipe = $this->entityManager->find(Recipe::class, Uuid::fromString($id));
        if (null === $recipe || !$recipe->getHousehold()->getId()->equals($plan->getHousehold()->getId())) {
            throw new UnprocessableEntityHttpException('That recipe is not in your library.');
        }

        return $recipe;
    }

    private function plan(mixed $id): MealPlan
    {
        // API Platform already turns {id} into a Uuid; be lenient with plain strings too.
        $uuid = $id instanceof Uuid ? $id : (\is_string($id) && Uuid::isValid($id) ? Uuid::fromString($id) : null);
        $plan = null === $uuid ? null : $this->plans->find($uuid);
        if (null === $plan || !$plan->getHousehold()->getId()->equals($this->current->household()->getId())) {
            throw new NotFoundHttpException('Not Found');
        }

        return $plan;
    }

    private function entry(MealPlan $plan, MealPlanEntryAction $input): MealPlanEntry
    {
        return $plan->entry($input->entry) ?? throw new NotFoundHttpException('That meal is not in this plan.');
    }
}
