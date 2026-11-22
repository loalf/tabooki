<?php

declare(strict_types=1);

namespace App\Taste;

use App\Entity\Household;
use App\Entity\SwapFeedback;
use App\Entity\TasteProfile;
use App\Entity\User;
use App\Enum\Heaviness;
use App\Enum\MealType;
use App\Enum\SwapReason;
use App\Repository\SwapFeedbackRepository;
use App\Repository\TasteProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the household's swaps say about its likings (docs/PLAN.md §6.3), by counting, no model:
 * - the same food group or ingredient disliked in two different dishes (last four months):
 *   rather without it;
 * - three dinners (or lunches) swapped for being too filling, or too light (last two months):
 *   that meal, light (or hearty);
 * - three weekday meals swapped for taking too long (last two months): a note about it, which
 *   the notes judging then reads.
 * Nothing already in the likings, and nothing turned down before.
 */
final readonly class SwapLearning
{
    public function __construct(
        private SwapFeedbackRepository $feedback,
        private TasteProfileRepository $profiles,
        private EntityManagerInterface $entityManager,
        private NotesQueue $notes,
    ) {
    }

    /** @return list<TasteSuggestion> */
    public function suggestions(Household $household): array
    {
        $profile = $this->profiles->forHousehold($household);
        $rules = $profile?->getRules() ?? TasteRules::none();
        $dismissed = $profile?->getDismissedSuggestions() ?? [];
        $recent = $this->feedback->since($household, new \DateTimeImmutable('-120 days'));
        $lately = array_filter($recent, static fn (SwapFeedback $f): bool => $f->getCreatedAt() >= new \DateTimeImmutable('-60 days'));

        $suggestions = [];
        // Disliked: the same group or ingredient in two different dishes.
        $dislikes = [];
        foreach ($recent as $f) {
            $what = $f->getWhat();
            if (SwapReason::Dislike !== $f->getReason() || !\in_array($what['kind'] ?? null, ['group', 'ingredient'], true) || !\is_string($what['value'] ?? null)) {
                continue;
            }
            $key = $what['kind'].':'.$what['value'];
            $dislikes[$key]['what'] = $what;
            $dislikes[$key]['dishes'][$f->getRecipeTitle()] = true;
        }
        foreach ($dislikes as $key => $found) {
            $what = $found['what'];
            $value = (string) $what['value'];
            $titles = array_keys($found['dishes']);
            if (\count($titles) < 2) {
                continue;
            }
            if ('group' === $what['kind']) {
                if ([] !== array_filter($rules->excludeGroups, static fn (ExcludedGroup $r): bool => $r->group->value === $value)) {
                    continue;
                }
                $suggestion = $this->suggestion($key, 'excludeGroup', \count($titles), $titles);
                $suggestion->group = $value;
            } else {
                if ([] !== array_filter($rules->excludeIngredients, static fn (ExcludedIngredient $r): bool => IngredientMatch::any($r->keys, [$value]))) {
                    continue;
                }
                $suggestion = $this->suggestion($key, 'excludeIngredient', \count($titles), $titles);
                $name = $what['name'] ?? ['es' => str_replace('_', ' ', $value), 'en' => str_replace('_', ' ', $value)];
                $suggestion->name = ['es' => $name['es'], 'en' => $name['en']];
                $suggestion->ingredientKey = $value;
            }
            $suggestions[] = $suggestion;
        }
        // The most said first.
        usort($suggestions, static fn (TasteSuggestion $a, TasteSuggestion $b): int => [$b->count, $a->key] <=> [$a->count, $b->key]);

        // Too filling, or too light, for a meal: three times.
        foreach (MealType::cases() as $slot) {
            foreach ([SwapReason::TooHeavy->value => Heaviness::Light, SwapReason::TooLight->value => Heaviness::Hearty] as $reason => $wanted) {
                $found = array_values(array_filter($lately, static fn (SwapFeedback $f): bool => $f->getReason()->value === $reason && $f->getSlot() === $slot));
                if (\count($found) < 3 || $rules->heavinessFor($slot) === $wanted) {
                    continue;
                }
                $suggestion = $this->suggestion("heaviness:{$slot->value}:{$wanted->value}", 'mealHeaviness', \count($found), array_map(static fn (SwapFeedback $f): string => $f->getRecipeTitle(), $found));
                $suggestion->slot = $slot->value;
                $suggestion->heaviness = $wanted->value;
                $suggestions[] = $suggestion;
            }
        }

        // Too long on weekdays: three times.
        $long = array_values(array_filter($lately, static fn (SwapFeedback $f): bool => SwapReason::TooLong === $f->getReason() && $f->isWeekday()));
        if (\count($long) >= 3) {
            $minutes = array_filter(array_map(static fn (SwapFeedback $f): ?int => $f->getMinutes(), $long));
            $limit = [] === $minutes ? 45 : max(15, (int) (floor((min($minutes) - 1) / 15) * 15));
            $suggestion = $this->suggestion("long:{$limit}", 'note', \count($long), array_map(static fn (SwapFeedback $f): string => $f->getRecipeTitle(), $long));
            $suggestion->note = [
                'es' => "Entre semana, nada que lleve más de {$limit} minutos.",
                'en' => "On weekdays, nothing that takes more than {$limit} minutes.",
            ];
            $suggestions[] = $suggestion;
        }

        return array_values(array_filter($suggestions, static fn (TasteSuggestion $s): bool => !\in_array($s->key, $dismissed, true)));
    }

    /** Adds it to the likings; false when there is no such suggestion (any more). */
    public function accept(Household $household, string $key, User $by): bool
    {
        $suggestion = array_values(array_filter($this->suggestions($household), static fn (TasteSuggestion $s): bool => $s->key === $key))[0] ?? null;
        if (null === $suggestion) {
            return false;
        }
        $profile = $this->profileOf($household);
        $rules = $profile->getRules()->toArray();
        $notes = $profile->getNotes();
        switch ($suggestion->kind) {
            case 'excludeGroup':
                $rules['excludeGroups'][] = ['group' => $suggestion->group, 'strength' => Strength::Avoid->value];
                break;
            case 'excludeIngredient':
                $rules['excludeIngredients'][] = ['keys' => [$suggestion->ingredientKey], 'name' => $suggestion->name, 'strength' => Strength::Avoid->value];
                break;
            case 'mealHeaviness':
                $rules['mealHeaviness'][(string) $suggestion->slot] = $suggestion->heaviness;
                break;
            case 'note':
                $note = $suggestion->note[$by->getLocale()] ?? $suggestion->note['es'] ?? '';
                $notes = trim(($notes ?? '')."\n".$note);
                break;
        }
        $profile->adopt(TasteRules::fromArray($rules), $notes, $by);
        $profile->dismissSuggestion($key);
        $this->entityManager->flush();
        $this->notes->notesSaved($household, $profile->getNotes());

        return true;
    }

    public function dismiss(Household $household, string $key): void
    {
        $this->profileOf($household)->dismissSuggestion($key);
        $this->entityManager->flush();
    }

    /** A household that hasn't filled in the questionnaire can still take or turn down a suggestion. */
    private function profileOf(Household $household): TasteProfile
    {
        $profile = $this->profiles->forHousehold($household);
        if (null === $profile) {
            $profile = new TasteProfile($household);
            $this->entityManager->persist($profile);
        }

        return $profile;
    }

    /** @param list<string> $titles */
    private function suggestion(string $key, string $kind, int $count, array $titles): TasteSuggestion
    {
        $suggestion = new TasteSuggestion();
        $suggestion->key = $key;
        $suggestion->kind = $kind;
        $suggestion->count = $count;
        $suggestion->examples = \array_slice(array_values(array_unique($titles)), 0, 3);

        return $suggestion;
    }
}
