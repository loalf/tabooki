<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\ExtractionRun;
use App\Entity\MealPlan;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Planning\Candidate;
use App\Repository\RecipeRepository;
use App\Repository\TasteProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets a week's wish (docs/PLAN.md §6.3, the tweak box): the text model turns it into rules on
 * top of the household's, logged in extraction_run (step "tweak"). When the model can't be
 * reached, the wish is kept without rules: the week is planned as usual and the app offers to
 * try again.
 */
final readonly class WeekTweak
{
    public function __construct(
        private TasteCompiler $compiler,
        private TasteVocabulary $vocabulary,
        private TasteProfileRepository $profiles,
        private RecipeRepository $recipes,
        private EntityManagerInterface $entityManager,
        private Pricing $pricing,
        private LoggerInterface $logger,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function apply(MealPlan $plan, ?string $wish): void
    {
        $wish = null === $wish ? '' : trim($wish);
        if ('' === $wish) {
            $plan->setTweak(null, null);

            return;
        }

        $household = $plan->getHousehold();
        [$ingredients, $tags] = $this->vocabulary->of($household);
        $started = hrtime(true);
        try {
            [$rules, $label, $completion] = $this->compiler->compileWeek($wish, $this->profiles->rulesFor($household), $ingredients, $tags);
        } catch (LlmException $e) {
            $this->logger->warning('Reading the week\'s wish failed: {error}', ['error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($household, null, 'tweak', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $plan->setTweak($wish, null);

            return;
        }
        $this->entityManager->persist(new ExtractionRun(
            $household, null, 'tweak', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
            $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
        ));

        $taste = new Taste($rules);
        $avoids = [];
        foreach ($this->recipes->libraryOf($household) as $recipe) {
            if ([] !== $taste->conflicts(Candidate::fromRecipe($recipe))) {
                $avoids[] = $recipe->getId()->toRfc4122();
            }
        }
        $plan->setTweak($wish, $rules, $label, $avoids);
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
