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
 * @phpstan-type Entry array{id: string, date: string, slot: string, servings: int, locked: bool, leftoverOfId?: string|null, recipe: array{id: string, title: string}}
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
