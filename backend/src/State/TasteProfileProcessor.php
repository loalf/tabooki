<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TasteDraftView;
use App\ApiResource\TasteProfileView;
use App\Dto\TasteDraftInput;
use App\Dto\TasteProfileInput;
use App\Dto\TasteRulesInput;
use App\Entity\ExtractionRun;
use App\Entity\Household;
use App\Entity\TasteProfile;
use App\Enum\Language;
use App\Llm\LlmException;
use App\Llm\Pricing;
use App\Repository\RecipeRepository;
use App\Repository\TasteProfileRepository;
use App\Security\CurrentHousehold;
use App\Taste\NotesQueue;
use App\Taste\TasteCompiler;
use App\Taste\TasteImpact;
use App\Taste\TasteImpactView;
use App\Taste\TasteRules;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Saves or forgets the household's likings (then has the recipes judged against the notes),
 * turns the questionnaire into a draft with the text
 * model (logged in extraction_run as step "taste_profile"), and works out what rules would do
 * to the library.
 *
 * @implements ProcessorInterface<TasteProfileInput|TasteDraftInput|TasteRulesInput|null, TasteProfileView|TasteDraftView|TasteImpactView|null>
 */
final readonly class TasteProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentHousehold $current,
        private TasteProfileRepository $profiles,
        private RecipeRepository $recipes,
        private TasteProfileProvider $provider,
        private TasteCompiler $compiler,
        private TasteImpact $impact,
        private NotesQueue $notes,
        private EntityManagerInterface $entityManager,
        private Pricing $pricing,
        private LoggerInterface $logger,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TasteProfileView|TasteDraftView|TasteImpactView|null
    {
        $household = $this->current->household();

        return match (true) {
            $data instanceof TasteDraftInput => $this->draft($household, $data->answers),
            $data instanceof TasteRulesInput => $this->impact->of($household, TasteRules::fromArray($data->rules)),
            $data instanceof TasteProfileInput => $this->save($household, $data),
            default => $this->forget($household),
        };
    }

    private function save(Household $household, TasteProfileInput $input): TasteProfileView
    {
        $profile = $this->profiles->forHousehold($household);
        if (null === $profile) {
            $profile = new TasteProfile($household);
            $this->entityManager->persist($profile);
        }
        $summary = $input->summary;
        $summary = \is_string($summary['es'] ?? null) && \is_string($summary['en'] ?? null)
            ? ['es' => mb_substr($summary['es'], 0, 1000), 'en' => mb_substr($summary['en'], 0, 1000)]
            : null;
        $profile->update($input->answers, TasteRules::fromArray($input->rules), $input->notes, $summary, $this->current->user());
        $this->entityManager->flush();
        $this->notes->notesSaved($household, $profile->getNotes());

        return $this->provider->view($profile);
    }

    private function forget(Household $household): null
    {
        $profile = $this->profiles->forHousehold($household);
        if (null !== $profile) {
            $this->entityManager->remove($profile);
            $this->entityManager->flush();
        }

        return null;
    }

    /** @param array<string, mixed> $answers */
    private function draft(Household $household, array $answers): TasteDraftView
    {
        [$ingredients, $tags] = $this->vocabulary($household);
        $view = new TasteDraftView();
        $started = hrtime(true);
        try {
            [$draft, $completion] = $this->compiler->compile($answers, $ingredients, $tags, $this->current->user()->getLocale(), \is_array($answers['followUps'] ?? null) && [] !== $answers['followUps']);
            $this->entityManager->persist(new ExtractionRun(
                $household, null, 'taste_profile', 'bedrock', $completion->model, $completion->inputTokens, $completion->outputTokens,
                $this->pricing->cost($completion->model, $completion->inputTokens, $completion->outputTokens), true, null, self::elapsedMs($started),
            ));
            $view->rules = $draft->rules->toArray();
            $view->notes = $draft->notes;
            $view->summary = $draft->summary;
            $view->questions = $draft->questions;
        } catch (LlmException $e) {
            // Still useful: what the tapped answers say, and the free text kept word for word.
            $this->logger->warning('Compiling the taste profile failed: {error}', ['error' => $e->getMessage()]);
            $this->entityManager->persist(new ExtractionRun($household, null, 'taste_profile', 'bedrock', $this->model, 0, 0, null, false, $e->getMessage(), self::elapsedMs($started)));
            $view->rules = TasteRules::fromAnswers($answers)->toArray();
            $other = \is_string($answers['other'] ?? null) ? trim($answers['other']) : '';
            $view->notes = '' === $other ? null : mb_substr($other, 0, 2000);
            $view->fallback = true;
        }
        $this->entityManager->flush();
        $view->impact = $this->impact->of($household, TasteRules::fromArray($view->rules));

        return $view;
    }

    /**
     * The words the household's recipes use, so the model maps "cilantro" or "more legumes" onto
     * keys and tags that exist.
     *
     * @return array{array<string, string>, array<string, int>} ingredient keys with a name, English tags with counts
     */
    private function vocabulary(Household $household): array
    {
        $ingredients = [];
        $tags = [];
        foreach ($this->recipes->libraryOf($household) as $recipe) {
            foreach ($recipe->getIngredients() as $ingredient) {
                $key = $ingredient->getCanonicalKey();
                if (null !== $key && '' !== $key && !isset($ingredients[$key])) {
                    $ingredients[$key] = $ingredient->getName() ?? $ingredient->getRawText();
                }
            }
            foreach ($recipe->tagsIn(Language::En) as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
        ksort($ingredients);
        arsort($tags);

        return [\array_slice($ingredients, 0, 600, true), \array_slice($tags, 0, 150, true)];
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
