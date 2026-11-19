<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\MealPlanInput;
use App\Entity\MealPlan;
use App\Entity\MealPlanEntry;
use App\Enum\MealType;
use App\Planning\Planner;
use App\Repository\MealPlanRepository;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Plans a week: creates its meals and fills them from the library.
 *
 * @implements ProcessorInterface<MealPlanInput, MealPlan>
 */
final readonly class CreateMealPlanProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private MealPlanRepository $plans,
        private Planner $planner,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param MealPlanInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MealPlan
    {
        $household = $this->current->household();
        $day = self::date($data->weekStart);
        if (null !== $this->plans->findForWeek($household, $day)) {
            throw new ConflictHttpException('This week already has a plan.');
        }

        $plan = new MealPlan($household, $day);
        $plan->setLeftovers($data->leftovers);
        $plan->setWeekdayMaxMinutes($data->weekdayMaxMinutes);
        foreach ($data->slots as $slot) {
            $date = self::date($slot->date);
            if (!$plan->contains($date)) {
                throw new UnprocessableEntityHttpException(\sprintf('%s is not in the week of %s.', $slot->date, $plan->getWeekStart()));
            }
            $type = MealType::from($slot->slot);
            if (null === $plan->entryAt($date, $type)) {
                $plan->addEntry(new MealPlanEntry($plan, $date, $type, $slot->servings ?? $household->getDefaultServings()));
            }
        }

        $this->planner->fill($plan);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    public static function date(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: throw new UnprocessableEntityHttpException('Dates are YYYY-MM-DD.');
    }
}
