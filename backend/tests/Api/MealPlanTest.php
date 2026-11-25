<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\MealType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type Entry array{id: string, date: string, slot: string, servings: int, locked: bool, rating?: int|null, cooked?: bool|null, leftoverOfId?: string|null, reasons?: list<array<string, mixed>>, recipe: array{id: string, title: string}}
 * @phpstan-type Plan array{id: string, weekStart: string, entries: list<Entry>}
 */
final class MealPlanTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    /** Monday. */
    private const string WEEK = '2026-11-23';

    private Client $client;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->signIn($this->client, $this->ana);
    }

    public function testPlansTheChosenMealsWithRecipesThatSuitThem(): void
    {
        $lunches = $this->recipes(8, 'Cocido', [MealType::Lunch]);
        $dinners = $this->recipes(8, 'Crema', [MealType::Dinner]);

        $plan = $this->createPlan($this->allSlots(skip: ['2026-11-28 dinner']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::WEEK, $plan['weekStart']);
        self::assertCount(13, $plan['entries']);
        self::assertSame(['2026-11-23', 'lunch'], [$plan['entries'][0]['date'], $plan['entries'][0]['slot']]);
        self::assertSame(['2026-11-23', 'dinner'], [$plan['entries'][1]['date'], $plan['entries'][1]['slot']]);

        $used = [];
        foreach ($plan['entries'] as $entry) {
            self::assertSame(4, $entry['servings'], 'the household default');
            self::assertFalse($entry['locked']);
            $title = $entry['recipe']['title'];
            self::assertStringStartsWith('lunch' === $entry['slot'] ? 'Cocido' : 'Crema', $title);
            $used[] = $title;
        }
        self::assertSame($used, array_unique($used), 'no recipe twice in a week while there are others');
        self::assertNotContains(['2026-11-28', 'dinner'], array_map(static fn (array $e): array => [$e['date'], $e['slot']], $plan['entries']));
        self::assertCount(16, [...$lunches, ...$dinners]);

        // The week can be found by any of its days.
        /** @var list<Plan> $found */
        $found = $this->client->request('GET', '/api/meal-plans?week=2026-11-26')->toArray();
        self::assertCount(1, $found);
        self::assertSame($plan['id'], $found[0]['id']);
        self::assertSame([], $this->client->request('GET', '/api/meal-plans?week=2026-11-30')->toArray());

        $this->createPlan([['date' => '2026-11-24', 'slot' => 'lunch']]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testLeftoversAreTheNextLunch(): void
    {
        $this->recipes(10, 'Plato', []);

        $plan = $this->createPlan($this->allSlots(), leftovers: true);

        $entries = $this->bySlot($plan);
        self::assertNull($entries['2026-11-23 lunch']['leftoverOfId'] ?? null, 'Monday lunch has no dinner before it');
        foreach (['2026-11-24', '2026-11-25', '2026-11-29'] as $day) {
            $previous = (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
            self::assertSame($entries[$previous.' dinner']['id'], $entries[$day.' lunch']['leftoverOfId'] ?? null);
            self::assertSame($entries[$previous.' dinner']['recipe']['id'], $entries[$day.' lunch']['recipe']['id']);
        }

        // Swapping the dinner takes its leftovers along; swapping the lunch cooks something new.
        $tuesdayLunch = $entries['2026-11-24 lunch'];
        $swapped = $this->bySlot($this->action($plan, 'swap', ['entry' => $entries['2026-11-23 dinner']['id']]));
        self::assertNotSame($entries['2026-11-23 dinner']['recipe']['id'], $swapped['2026-11-23 dinner']['recipe']['id']);
        self::assertSame($swapped['2026-11-23 dinner']['recipe']['id'], $swapped['2026-11-24 lunch']['recipe']['id']);

        $swapped = $this->bySlot($this->action($plan, 'swap', ['entry' => $tuesdayLunch['id']]));
        self::assertNull($swapped['2026-11-24 lunch']['leftoverOfId'] ?? null);
        self::assertNotSame($swapped['2026-11-23 dinner']['recipe']['id'], $swapped['2026-11-24 lunch']['recipe']['id']);
    }

    public function testRegenerateKeepsLockedMealsAndRespectsTheWeekdayTimeLimit(): void
    {
        foreach (range(1, 12) as $i) {
            $this->recipe('Rápida '.$i, minutes: 20);
        }
        $slow = $this->recipe('Asado lento', minutes: 180);

        $plan = $this->createPlan($this->allSlots(), weekdayMaxMinutes: 45);
        foreach ($this->bySlot($plan) as $key => $entry) {
            if ((int) (new \DateTimeImmutable(substr($key, 0, 10)))->format('N') <= 5) {
                self::assertNotSame('Asado lento', $entry['recipe']['title'], 'too long for a weekday');
            }
        }

        $first = $plan['entries'][0];
        $this->action($plan, 'update', ['entry' => $first['id'], 'locked' => true, 'servings' => 2]);
        $regenerated = self::plan($this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/regenerate')->toArray());

        self::assertResponseIsSuccessful();
        self::assertSame($first['recipe']['id'], $regenerated['entries'][0]['recipe']['id']);
        self::assertTrue($regenerated['entries'][0]['locked']);
        self::assertSame(2, $regenerated['entries'][0]['servings']);
        self::assertSame('Asado lento', $slow->getTitle());
    }

    public function testMealsCanBeChosenAddedAndRemoved(): void
    {
        $this->recipes(3, 'Plato', []);
        $mine = $this->recipe('Lentejas de la abuela');

        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'lunch', 'servings' => 6]]);
        self::assertSame(6, $plan['entries'][0]['servings']);

        $plan = $this->action($plan, 'update', ['entry' => $plan['entries'][0]['id'], 'recipe' => $mine->getId()->toRfc4122()]);
        self::assertSame('Lentejas de la abuela', $plan['entries'][0]['recipe']['title']);

        $plan = self::plan($this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries', ['json' => ['date' => '2026-11-27', 'slot' => 'dinner']])->toArray());
        self::assertCount(2, $plan['entries']);
        self::assertArrayHasKey('recipe', $plan['entries'][1]);

        $this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries', ['json' => ['date' => '2026-12-01', 'slot' => 'dinner']]);
        self::assertResponseStatusCodeSame(422, 'not in this week');

        $plan = $this->action($plan, 'remove', ['entry' => $plan['entries'][0]['id']]);
        self::assertCount(1, $plan['entries']);

        $this->client->request('DELETE', '/api/meal-plans/'.$plan['id']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->client->request('GET', '/api/meal-plans')->toArray());
    }

    public function testRatingAMealRatesItsRecipeAndKeepsIt(): void
    {
        $lentejas = $this->recipe('Lentejas');
        $garbanzos = $this->recipe('Garbanzos');
        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'lunch']]);
        $entry = $plan['entries'][0]['id'];
        $plan = $this->action($plan, 'update', ['entry' => $entry, 'recipe' => $lentejas->getId()->toRfc4122()]);

        $plan = $this->action($plan, 'update', ['entry' => $entry, 'rating' => 4]);
        self::assertSame(4, $plan['entries'][0]['rating'] ?? null);
        self::assertTrue($plan['entries'][0]['locked'], 'eaten: regenerating keeps it');
        self::assertSame(4, $this->client->request('GET', '/api/recipes/'.$lentejas->getId())->toArray()['rating']);

        $this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/update', ['json' => ['entry' => $entry, 'rating' => 6]]);
        self::assertResponseStatusCodeSame(422);

        // Another recipe for that meal: the rating was about the old one.
        $plan = $this->action($plan, 'update', ['entry' => $entry, 'recipe' => $garbanzos->getId()->toRfc4122()]);
        self::assertNull($plan['entries'][0]['rating'] ?? null);
        self::assertSame(4, $this->client->request('GET', '/api/recipes/'.$lentejas->getId())->toArray()['rating'], 'the recipe keeps it');
    }

    public function testRecentWeeksAreNotRepeatedWhileThereAreOtherRecipes(): void
    {
        $this->recipes(8, 'Plato', []);
        $lastWeek = $this->createPlan($this->allSlots(only: 3, week: '2026-11-16'), week: '2026-11-16');
        $lastWeeksRecipes = array_map(static fn (array $e): string => $e['recipe']['id'], $lastWeek['entries']);

        $plan = $this->createPlan($this->allSlots(only: 3));

        foreach ($plan['entries'] as $entry) {
            self::assertNotContains($entry['recipe']['id'], $lastWeeksRecipes);
        }
    }

    public function testRecipesAskedForComeFirstInTheNextMenu(): void
    {
        $this->recipes(8, 'Plato', []);
        $wanted = $this->recipe('Lentejas de la abuela', [MealType::Dinner]);
        $this->client->request('PATCH', '/api/recipes/'.$wanted->getId(), [
            'json' => ['planNext' => true],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        // Only where it fits: not a lunch, as it's a dinner.
        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'lunch'], ['date' => '2026-11-24', 'slot' => 'dinner']]);
        self::assertNotSame('Lentejas de la abuela', $plan['entries'][0]['recipe']['title']);
        self::assertSame('Lentejas de la abuela', $plan['entries'][1]['recipe']['title']);

        /** @var array{planNext: bool} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$wanted->getId())->toArray();
        self::assertFalse($recipe['planNext'], 'planned: no longer waiting');
    }

    public function testRecipesKnowWhenTheyWereLastOnTheMenu(): void
    {
        $lentejas = $this->recipe('Lentejas');
        $this->recipe('Nunca');
        $past = $this->createPlan([['date' => '2026-11-02', 'slot' => 'lunch'], ['date' => '2026-11-04', 'slot' => 'lunch']], week: '2026-11-02');
        $this->action($past, 'update', ['entry' => $past['entries'][0]['id'], 'recipe' => $lentejas->getId()->toRfc4122()]);
        $this->action($past, 'update', ['entry' => $past['entries'][1]['id'], 'recipe' => $lentejas->getId()->toRfc4122()]);
        // Planned for later doesn't count yet.
        $future = $this->createPlan([['date' => '2099-01-05', 'slot' => 'lunch']], week: '2099-01-05');
        $this->action($future, 'update', ['entry' => $future['entries'][0]['id'], 'recipe' => $lentejas->getId()->toRfc4122()]);

        /** @var list<array{title: string, lastCookedOn?: string|null}> $list */
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        $byTitle = array_column($list, 'lastCookedOn', 'title');
        self::assertSame('2026-11-04', $byTitle['Lentejas'] ?? null);
        self::assertNull($byTitle['Nunca'] ?? null);

        /** @var array{lastCookedOn?: string|null} $one */
        $one = $this->client->request('GET', '/api/recipes/'.$lentejas->getId())->toArray();
        self::assertSame('2026-11-04', $one['lastCookedOn'] ?? null);
    }

    public function testAMealTakenOffComesBackAsItWasWithItsLeftovers(): void
    {
        $this->recipes(8, 'Plato', []);
        $plan = $this->createPlan($this->allSlots(), leftovers: true);
        $dinner = $this->bySlot($plan)['2026-11-23 dinner'];
        $lunch = $this->bySlot($plan)['2026-11-24 lunch'];
        self::assertSame($dinner['id'], $lunch['leftoverOfId'] ?? null);
        $plan = $this->action($plan, 'update', ['entry' => $dinner['id'], 'rating' => 4]);
        $dinner = $this->bySlot($plan)['2026-11-23 dinner'];

        $plan = $this->action($plan, 'remove', ['entry' => $dinner['id']]);
        self::assertNull($this->bySlot($plan)['2026-11-24 lunch']['leftoverOfId'] ?? null, 'removing unlinks its leftovers');

        $snapshot = [
            'entry' => $dinner['id'],
            'date' => $dinner['date'],
            'slot' => $dinner['slot'],
            'servings' => $dinner['servings'],
            'recipe' => $dinner['recipe']['id'],
            'locked' => $dinner['locked'],
            'rating' => $dinner['rating'] ?? null,
            'cooked' => $dinner['cooked'] ?? null,
            'reasons' => $dinner['reasons'] ?? [],
            'leftovers' => [$lunch['id']],
        ];
        $plan = $this->action($plan, 'restore', $snapshot);
        self::assertResponseIsSuccessful();
        $back = $this->bySlot($plan)['2026-11-23 dinner'];
        self::assertSame($dinner['id'], $back['id'], 'the same meal');
        self::assertSame($dinner['recipe']['id'], $back['recipe']['id']);
        self::assertSame(4, $back['rating'] ?? null);
        self::assertTrue($back['cooked'] ?? null);
        self::assertTrue($back['locked']);
        self::assertSame($dinner['reasons'] ?? [], $back['reasons'] ?? []);
        self::assertSame($dinner['id'], $this->bySlot($plan)['2026-11-24 lunch']['leftoverOfId'] ?? null, 'its leftovers again');

        $this->action($plan, 'restore', $snapshot);
        self::assertResponseStatusCodeSame(409, 'already back');

        // Leftovers taken off come back as the leftovers of their meal.
        $plan = $this->action($plan, 'remove', ['entry' => $lunch['id']]);
        $plan = $this->action($plan, 'restore', [
            'entry' => $lunch['id'],
            'date' => $lunch['date'],
            'slot' => $lunch['slot'],
            'servings' => $lunch['servings'],
            'recipe' => $lunch['recipe']['id'],
            'locked' => false,
            'leftoverOf' => $dinner['id'],
        ]);
        self::assertSame($dinner['id'], $this->bySlot($plan)['2026-11-24 lunch']['leftoverOfId'] ?? null);
    }

    public function testTheHouseholdSaysWhetherAMealWasCooked(): void
    {
        $lentejas = $this->recipe('Lentejas');
        $garbanzos = $this->recipe('Garbanzos');
        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'lunch']]);
        $entry = $plan['entries'][0]['id'];
        $plan = $this->action($plan, 'update', ['entry' => $entry, 'recipe' => $lentejas->getId()->toRfc4122()]);
        self::assertNull($plan['entries'][0]['cooked'] ?? null, 'nobody has said');

        $plan = $this->action($plan, 'update', ['entry' => $entry, 'cooked' => false]);
        self::assertFalse($plan['entries'][0]['cooked'] ?? null);
        self::assertTrue($plan['entries'][0]['locked'], 'what happened: regenerating keeps it');

        // Undone: null is a value, not "leave it as it is".
        $plan = $this->action($plan, 'update', ['entry' => $entry, 'cooked' => null, 'locked' => false]);
        self::assertNull($plan['entries'][0]['cooked'] ?? null);
        self::assertFalse($plan['entries'][0]['locked']);

        $plan = $this->action($plan, 'update', ['entry' => $entry, 'servings' => 3]);
        self::assertNull($plan['entries'][0]['cooked'] ?? null, 'not sent: unchanged');

        $plan = $this->action($plan, 'update', ['entry' => $entry, 'cooked' => true]);
        self::assertTrue($plan['entries'][0]['cooked'] ?? null);

        // Another recipe: what was cooked was the old one.
        $plan = $this->action($plan, 'update', ['entry' => $entry, 'recipe' => $garbanzos->getId()->toRfc4122()]);
        self::assertNull($plan['entries'][0]['cooked'] ?? null);

        $plan = $this->action($plan, 'update', ['entry' => $entry, 'rating' => 5]);
        self::assertTrue($plan['entries'][0]['cooked'] ?? null, 'rated: it was eaten');
    }

    public function testLeftoversOfAMealNotMadeAreThatDishToCook(): void
    {
        $this->recipes(8, 'Plato', []);
        $now = new \DateTimeImmutable('today');
        $yesterday = $now->modify('-1 day');
        $plan = $this->createPlan([['date' => $yesterday->format('Y-m-d'), 'slot' => 'dinner'], ['date' => $now->format('Y-m-d'), 'slot' => 'lunch']], leftovers: true, week: $yesterday->modify('monday this week')->format('Y-m-d'));
        if (2 !== \count($plan['entries'])) {
            self::markTestSkipped('On Mondays yesterday is in another week.');
        }
        $dinner = $this->bySlot($plan)[$yesterday->format('Y-m-d').' dinner'];
        $lunch = $this->bySlot($plan)[$now->format('Y-m-d').' lunch'];
        self::assertSame($dinner['id'], $lunch['leftoverOfId'] ?? null);

        $plan = $this->action($plan, 'update', ['entry' => $dinner['id'], 'cooked' => false]);
        $lunch = $this->bySlot($plan)[$now->format('Y-m-d').' lunch'];
        self::assertNull($lunch['leftoverOfId'] ?? null, 'cooked today instead');
        self::assertSame($dinner['recipe']['id'], $lunch['recipe']['id'], 'the same dish');

        // Undone: back to leftovers.
        $this->action($plan, 'update', ['entry' => $dinner['id'], 'cooked' => null, 'locked' => false]);
        $plan = $this->action($plan, 'update', ['entry' => $lunch['id'], 'leftoverOf' => $dinner['id']]);
        self::assertSame($dinner['id'], $this->bySlot($plan)[$now->format('Y-m-d').' lunch']['leftoverOfId'] ?? null);

        $this->action($plan, 'update', ['entry' => $dinner['id'], 'leftoverOf' => $lunch['id']]);
        self::assertResponseStatusCodeSame(422, 'leftovers come after');
    }

    public function testLastCookedCountsWhatWasCooked(): void
    {
        $skipped = $this->recipe('Saltada');
        $today = $this->recipe('Hoy');
        $done = $this->recipe('Hecha hoy');
        $now = new \DateTimeImmutable('today');
        $monday = $now->modify('monday this week');
        $lastWeek = $now->modify('-7 days');
        $week = $monday->format('Y-m-d');

        $past = $this->createPlan([['date' => $lastWeek->format('Y-m-d'), 'slot' => 'lunch']], week: $lastWeek->modify('monday this week')->format('Y-m-d'));
        $this->action($past, 'update', ['entry' => $past['entries'][0]['id'], 'recipe' => $skipped->getId()->toRfc4122()]);
        $this->action($past, 'update', ['entry' => $past['entries'][0]['id'], 'cooked' => false]);

        $slots = [['date' => $now->format('Y-m-d'), 'slot' => 'lunch'], ['date' => $now->format('Y-m-d'), 'slot' => 'dinner']];
        $plan = $this->createPlan($slots, week: $week);
        $entries = $this->bySlot($plan);
        $this->action($plan, 'update', ['entry' => $entries[$now->format('Y-m-d').' lunch']['id'], 'recipe' => $today->getId()->toRfc4122()]);
        $this->action($plan, 'update', ['entry' => $entries[$now->format('Y-m-d').' dinner']['id'], 'recipe' => $done->getId()->toRfc4122()]);
        $this->action($plan, 'update', ['entry' => $entries[$now->format('Y-m-d').' dinner']['id'], 'cooked' => true]);

        /** @var list<array{title: string, lastCookedOn?: string|null}> $list */
        $list = $this->client->request('GET', '/api/recipes')->toArray();
        $byTitle = array_column($list, 'lastCookedOn', 'title');
        self::assertNull($byTitle['Saltada'] ?? null, 'not cooked');
        self::assertNull($byTitle['Hoy'] ?? null, 'not until it is done');
        self::assertSame($now->format('Y-m-d'), $byTitle['Hecha hoy'] ?? null);
    }

    public function testLeftoversDontCountAsCooking(): void
    {
        $this->recipes(8, 'Plato', []);
        $plan = $this->createPlan([['date' => '2026-11-02', 'slot' => 'dinner'], ['date' => '2026-11-03', 'slot' => 'lunch']], leftovers: true, week: '2026-11-02');
        $dinner = $this->bySlot($plan)['2026-11-02 dinner'];
        self::assertSame($dinner['id'], $this->bySlot($plan)['2026-11-03 lunch']['leftoverOfId'] ?? null);

        /** @var array{lastCookedOn?: string|null} $recipe */
        $recipe = $this->client->request('GET', '/api/recipes/'.$dinner['recipe']['id'])->toArray();
        self::assertSame('2026-11-02', $recipe['lastCookedOn'] ?? null, 'the day it was cooked, not the leftovers');
    }

    public function testAnEmptyLibraryLeavesMealsEmpty(): void
    {
        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'dinner']]);

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('recipe', $plan['entries'][0]);
    }

    public function testPlansArePrivateToTheHousehold(): void
    {
        $plan = $this->createPlan([['date' => '2026-11-23', 'slot' => 'dinner']]);

        $other = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->signIn($other, $this->createUser('eva@example.com', 'Otra casa'));
        $other->request('GET', '/api/meal-plans/'.$plan['id']);
        self::assertResponseStatusCodeSame(404);
        $other->request('POST', '/api/meal-plans/'.$plan['id'].'/regenerate');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $other->request('GET', '/api/meal-plans?week='.self::WEEK)->toArray());

        // Nor can another household's recipe be put in a plan.
        $theirs = $this->recipe('Ajeno', household: $this->createUser('leo@example.com', 'Tercera casa'));
        // (Checked on the response itself: the assertion helpers read the newest client, $other.)
        $response = $this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/update', ['json' => ['entry' => $plan['entries'][0]['id'], 'recipe' => $theirs->getId()->toRfc4122()]]);
        self::assertSame(422, $response->getStatusCode());
    }

    // —— Helpers ————————————————————————————————————————————————

    /**
     * @param list<array{date: string, slot: string, servings?: int}> $slots
     *
     * @return Plan
     */
    private function createPlan(array $slots, bool $leftovers = false, ?int $weekdayMaxMinutes = null, string $week = self::WEEK): array
    {
        return self::plan($this->client->request('POST', '/api/meal-plans', ['json' => [
            'weekStart' => $week,
            'slots' => $slots,
            'leftovers' => $leftovers,
            'weekdayMaxMinutes' => $weekdayMaxMinutes,
        ]])->toArray(false));
    }

    /**
     * Responses as the API documents them (the assertions check the details).
     *
     * @param array<mixed> $data
     *
     * @return Plan
     */
    private static function plan(array $data): array
    {
        /** @var Plan $plan */
        $plan = $data;

        return $plan;
    }

    /**
     * @param Plan                 $plan
     * @param array<string, mixed> $body
     *
     * @return Plan
     */
    private function action(array $plan, string $action, array $body): array
    {
        return self::plan($this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/'.$action, ['json' => $body])->toArray(false));
    }

    /**
     * @param list<string> $skip "YYYY-MM-DD slot" to leave out
     *
     * @return list<array{date: string, slot: string}>
     */
    private function allSlots(array $skip = [], ?int $only = null, string $week = self::WEEK): array
    {
        $slots = [];
        foreach (range(0, 6) as $day) {
            $date = (new \DateTimeImmutable($week))->modify("+$day days")->format('Y-m-d');
            foreach (['lunch', 'dinner'] as $slot) {
                if (!\in_array("$date $slot", $skip, true)) {
                    $slots[] = ['date' => $date, 'slot' => $slot];
                }
            }
        }

        return null === $only ? $slots : \array_slice($slots, 0, $only);
    }

    /**
     * @param Plan $plan
     *
     * @return array<string, Entry> entries by "YYYY-MM-DD slot"
     */
    private function bySlot(array $plan): array
    {
        $entries = [];
        foreach ($plan['entries'] as $entry) {
            $entries[$entry['date'].' '.$entry['slot']] = $entry;
        }

        return $entries;
    }

    /**
     * @param list<MealType> $mealTypes
     *
     * @return list<Recipe>
     */
    private function recipes(int $count, string $prefix, array $mealTypes): array
    {
        return array_map(fn (int $i): Recipe => $this->recipe("$prefix $i", $mealTypes), range(1, $count));
    }

    /** @param list<MealType> $mealTypes */
    private function recipe(string $title, array $mealTypes = [], ?int $minutes = null, ?User $household = null): Recipe
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, ($household ?? $this->ana)->getId());
        self::assertNotNull($owner);

        $recipe = new Recipe();
        $recipe->assignHousehold($owner->getHousehold());
        $recipe->setTitle($title);
        $recipe->setMealTypes($mealTypes);
        $recipe->setCookMinutes($minutes);
        $ingredient = new RecipeIngredient();
        $ingredient->setRawText('1 cebolla');
        $ingredient->setCanonicalKey('onion');
        $recipe->setIngredients([$ingredient]);
        $entityManager->persist($recipe);
        $entityManager->flush();

        return $recipe;
    }
}
