<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\ExtractionRun;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Enum\MealType;
use App\Message\JudgeNotes;
use App\MessageHandler\JudgeNotesHandler;
use App\Planning\RecipeFacts;
use App\Taste\NotesJudge;
use App\Taste\Taste;
use App\Taste\TasteCompiler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What the household likes (docs/PLAN.md §6.1, §6.3): the questionnaire becomes rules, and the
 * planner keeps to them.
 *
 * @phpstan-type Entry array{id: string, date: string, slot: string, leftoverOfId?: string|null, reasons: list<array{code: string, value?: string, conflicts?: list<array{strength: string, kind: string, value?: string}>}>, recipe?: array{id: string, title: string}|null}
 * @phpstan-type Plan array{id: string, entries: list<Entry>}
 */
final class TasteProfileTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private const string WEEK = '2026-11-23';

    private Client $client;
    private User $ana;

    /** @var list<array<mixed>> */
    private array $bedrockRequests = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        // One kernel for the whole test: the Bedrock mock lives in its container.
        $this->client->disableReboot();
        $this->ana = $this->createUser('ana@example.com');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signIn($this->client, $this->ana);
    }

    public function testTheQuestionnaireBecomesADraftToReview(): void
    {
        $this->client->request('GET', '/api/taste-profile');
        self::assertResponseStatusCodeSame(404, 'nothing said yet');

        $this->recipe('Merluza en salsa verde', [FoodGroup::Fish], keys: ['hake', 'fresh_parsley']);
        $this->recipe('Pollo al cilantro', [FoodGroup::Poultry], keys: ['chicken', 'fresh_coriander']);
        $this->recipe('Lentejas', [], keys: ['lentils'], tags: ['legumes']);

        // The model drops the fish allergy: it stays anyway.
        $this->mockBedrock([$this->answer([
            'diet' => null,
            'excludeGroups' => [],
            'excludeIngredients' => [['keys' => ['coriander', 'fresh_coriander'], 'name' => ['es' => 'cilantro', 'en' => 'coriander'], 'strength' => 'avoid']],
            'weekly' => [['groups' => ['red_meat', 'pork', 'poultry', 'processed_meat', 'fish', 'shellfish'], 'max' => 2, 'min' => null]],
            'mealHeaviness' => ['lunch' => 'hearty', 'dinner' => 'light'],
            'prefer' => [['tags' => ['legumes'], 'name' => ['es' => 'legumbres', 'en' => 'legumes'], 'weight' => 'more']],
            'maxSpice' => null,
        ], questions: [['question' => '¿El cilantro seco también?', 'options' => ['Sí', 'No']]])]);

        $draft = $this->draft([
            'avoid' => [['item' => 'fish', 'strength' => 'never'], ['item' => 'cilantro', 'custom' => true, 'strength' => 'avoid']],
            'animalProteinMax' => 2,
            'meals' => 'heavyLunch',
            'more' => ['legumes'],
            'other' => 'Los niños no comen nada con salsa.',
        ]);

        // What the model was given: the answers, the draft from them, and the household's own words.
        /** @var array{draft_rules: array{excludeGroups: mixed}, household_ingredients: list<string>, household_tags: array<string, int>} $sent */
        $sent = json_decode($this->userText($this->bedrockRequests[0]), true);
        self::assertSame([['group' => 'fish', 'strength' => 'never']], $sent['draft_rules']['excludeGroups'] ?? null);
        self::assertContains('fresh_coriander (fresh_coriander)', $sent['household_ingredients']);
        self::assertSame(['legumes' => 1], $sent['household_tags']);
        self::assertStringContainsString('up to 3 multiple-choice follow-ups, in Spanish', $this->system($this->bedrockRequests[0]));

        self::assertSame([['group' => 'fish', 'strength' => 'never']], $draft['rules']['excludeGroups'], 'an allergy tapped in the questionnaire stays');
        self::assertSame('cilantro', $draft['rules']['excludeIngredients'][0]['name']['es']);
        self::assertSame(['lunch' => 'hearty', 'dinner' => 'light'], $draft['rules']['mealHeaviness']);
        self::assertSame('Los niños no comen nada con salsa.', $draft['notes']);
        self::assertSame('Coméis de todo menos pescado.', $draft['summary']['es'] ?? null);
        self::assertSame([['question' => '¿El cilantro seco también?', 'options' => ['Sí', 'No']]], $draft['questions']);
        self::assertFalse($draft['fallback']);

        // What it does to the library: one recipe out for each rule, one left.
        self::assertSame(3, $draft['impact']['library']);
        self::assertSame([1], $draft['impact']['excludeGroups']);
        self::assertSame([1], $draft['impact']['excludeIngredients']);
        self::assertSame(1, $draft['impact']['allowed']);

        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'taste_profile']);
        self::assertCount(1, $runs);

        // Answering the follow-ups: no more questions, whatever the model says.
        $this->mockBedrock([$this->answer($draft['rules'], questions: [['question' => '¿Otra?', 'options' => ['Sí', 'No']]])]);
        $second = $this->draft(['avoid' => [], 'followUps' => [['question' => '¿El cilantro seco también?', 'answer' => 'No']]]);
        self::assertSame([], $second['questions']);
        self::assertStringContainsString('questions: always empty', $this->system($this->bedrockRequests[0]));
    }

    public function testWithoutTheModelTheTappedAnswersStillCount(): void
    {
        $this->mockBedrock([new MockResponse('', ['http_code' => 500])]);
        $draft = $this->draft(['diet' => 'vegetarian', 'spice' => 'none', 'meals' => 'heavyDinner', 'other' => 'Nada de setas.']);

        self::assertTrue($draft['fallback']);
        self::assertSame('vegetarian', $draft['rules']['diet']);
        self::assertSame(0, $draft['rules']['maxSpice']);
        self::assertSame(['lunch' => 'light', 'dinner' => 'hearty'], $draft['rules']['mealHeaviness']);
        self::assertSame('Nada de setas.', $draft['notes'], 'the free text is kept word for word');
        self::assertNull($draft['summary']);
    }

    public function testTheSavedProfileMarksRecipesThatDontFit(): void
    {
        $this->recipe('Merluza', [FoodGroup::Fish]);
        $this->recipe('Tortilla', [FoodGroup::Egg], optional: [FoodGroup::Cheese]);
        $this->recipe('Sin leer', null);

        $profile = $this->save(['excludeGroups' => [
            ['group' => 'fish', 'strength' => 'never'],
            ['group' => 'cheese', 'strength' => 'avoid'],
            ['group' => 'made_up', 'strength' => 'never'],
        ]], summary: ['es' => 'Sin pescado.', 'en' => 'No fish.']);
        self::assertSame([['group' => 'fish', 'strength' => 'never'], ['group' => 'cheese', 'strength' => 'avoid']], $profile['rules']['excludeGroups'], 'unknown groups dropped');
        self::assertSame('Ana', $profile['updatedBy']);
        self::assertSame(['es' => 'Sin pescado.', 'en' => 'No fish.'], $profile['summary']);
        self::assertSame(1, $profile['impact']['unread']);

        /** @var list<array{title: string, tasteConflicts?: list<array{strength: string, kind: string, value?: string}>}> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        $conflicts = array_column($recipes, 'tasteConflicts', 'title');
        self::assertSame([['strength' => 'never', 'kind' => 'group', 'value' => 'fish']], $conflicts['Merluza']);
        self::assertSame([], $conflicts['Tortilla'], 'a dislike doesn\'t count when the ingredient is optional');
        self::assertSame([['strength' => 'never', 'kind' => 'unknown']], $conflicts['Sin leer'], 'an allergy needs to know what it contains');

        $this->client->request('DELETE', '/api/taste-profile');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/taste-profile');
        self::assertResponseStatusCodeSame(404);
        /** @var list<array<string, mixed>> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertArrayNotHasKey('tasteConflicts', $recipes[0]);
    }

    public function testThePlannerKeepsToTheHouseholdsLikings(): void
    {
        foreach (range(1, 8) as $i) {
            $this->recipe("Ensalada $i", [], Heaviness::Light, keys: ['lettuce', "veg_$i"]);
            $this->recipe("Potaje $i", [], Heaviness::Hearty, keys: ['chickpeas', "veg_$i"]);
        }
        foreach (range(1, 4) as $i) {
            $this->recipe("Guiso $i", [FoodGroup::RedMeat], Heaviness::Hearty, keys: ['beef', "veg_$i"]);
            $this->recipe("Pescado $i", [FoodGroup::Fish], Heaviness::Light);
        }
        $this->save([
            'excludeGroups' => [['group' => 'fish', 'strength' => 'never']],
            'weekly' => [['groups' => ['red_meat', 'pork', 'poultry', 'processed_meat', 'fish', 'shellfish'], 'max' => 2, 'min' => null]],
            'mealHeaviness' => ['lunch' => 'hearty', 'dinner' => 'light'],
        ]);

        $plan = $this->createPlan();
        $titles = array_map(static fn (array $e): string => $e['recipe']['title'] ?? '', $plan['entries']);
        self::assertSame([], array_filter($titles, static fn (string $t): bool => str_starts_with($t, 'Pescado')), 'an allergy, never');
        self::assertLessThanOrEqual(2, \count(array_filter($titles, static fn (string $t): bool => str_starts_with($t, 'Guiso'))), 'meat twice at most');

        // A preference, not a rule: with plenty of each, (nearly) every meal follows it.
        $light = 0;
        $hearty = 0;
        foreach ($plan['entries'] as $entry) {
            $title = $entry['recipe']['title'] ?? '';
            if ('dinner' === $entry['slot'] && str_starts_with($title, 'Ensalada')) {
                ++$light;
                self::assertContains(['code' => 'heaviness', 'value' => 'light'], $entry['reasons']);
            }
            if ('lunch' === $entry['slot'] && 1 === preg_match('/^(Guiso|Potaje)/', $title)) {
                ++$hearty;
            }
        }
        self::assertGreaterThanOrEqual(6, $light, 'light dinners');
        self::assertGreaterThanOrEqual(6, $hearty, 'hearty lunches');
    }

    public function testWhatTheHouseholdCantEatLeavesAMealEmptyAndADislikeGivesWay(): void
    {
        $this->recipe('Merluza', [FoodGroup::Fish]);
        $this->recipe('Macarrones con queso', [FoodGroup::Cheese, FoodGroup::Gluten]);
        $this->save(['excludeGroups' => [['group' => 'fish', 'strength' => 'never'], ['group' => 'cheese', 'strength' => 'avoid']]]);

        $plan = $this->createPlan(2);
        $first = $plan['entries'][0];
        self::assertSame('Macarrones con queso', $first['recipe']['title'] ?? null, 'nothing else: the dislike gives way');
        self::assertSame('relaxedAvoid', $first['reasons'][0]['code']);
        self::assertSame([['strength' => 'avoid', 'kind' => 'group', 'value' => 'cheese']], $first['reasons'][0]['conflicts'] ?? null);

        $second = $plan['entries'][1];
        self::assertSame('Macarrones con queso', $second['recipe']['title'] ?? null, 'repeating beats an allergy');

        // Chosen by hand anyway: the meal says what it goes against.
        $fish = $this->recipeId('Merluza');
        $plan = self::plan($this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/entries/update', ['json' => ['entry' => $first['id'], 'recipe' => $fish]])->toArray());
        self::assertSame([['code' => 'breaks', 'conflicts' => [['strength' => 'never', 'kind' => 'group', 'value' => 'fish']]]], $plan['entries'][0]['reasons']);

        // Only fish left: the meal stays empty and says why.
        $this->save(['excludeGroups' => [['group' => 'fish', 'strength' => 'never'], ['group' => 'cheese', 'strength' => 'never']]]);
        $plan = self::plan($this->client->request('POST', '/api/meal-plans/'.$plan['id'].'/regenerate')->toArray());
        self::assertArrayNotHasKey('recipe', $plan['entries'][0]);
        self::assertSame([['code' => 'nothingAllowed']], $plan['entries'][0]['reasons']);
    }

    public function testAWeeklyMinimumIsReached(): void
    {
        foreach (range(1, 10) as $i) {
            $this->recipe("Verdura $i", []);
        }
        $this->recipe('Merluza', [FoodGroup::Fish]);
        $this->recipe('Salmón', [FoodGroup::Fish]);
        $this->save(['weekly' => [['groups' => ['fish', 'shellfish'], 'max' => null, 'min' => 2]]]);

        foreach (range(1, 5) as $_) {
            $plan = $this->createPlan(8);
            $titles = array_map(static fn (array $e): string => $e['recipe']['title'] ?? '', $plan['entries']);
            self::assertContains('Merluza', $titles);
            self::assertContains('Salmón', $titles);
            $this->client->request('DELETE', '/api/meal-plans/'.$plan['id']);
        }
    }

    public function testRecipesAreJudgedAgainstTheNotes(): void
    {
        $this->recipe('Cocido completo', [FoodGroup::RedMeat], keys: ['chickpeas', 'beef'], minutes: 180);
        $this->recipe('Tortilla francesa', [FoodGroup::Egg], keys: ['egg'], minutes: 10);
        $this->recipe('Ensalada', [], keys: ['lettuce']);
        $this->asyncTransport()->reset();

        $this->save([], notes: 'Entre semana, nada muy elaborado.');
        self::assertCount(1, $this->queued(JudgeNotes::class), 'saving the notes queues the judging');

        $this->mockBedrock([$this->judgements([
            ['n' => 1, 'fit' => 'poor', 'when' => 'weekdays', 'reason_es' => 'Laboriosa para entre semana', 'reason_en' => 'Fiddly for a weekday'],
            ['n' => 2, 'fit' => 'good', 'when' => 'weekdays', 'reason_es' => 'Rápida', 'reason_en' => 'Quick'],
            ['n' => 9, 'fit' => 'poor', 'when' => 'always', 'reason_es' => 'No existe', 'reason_en' => 'No such recipe'],
        ])]);
        $this->judgeNotes();

        $prompt = $this->userText($this->bedrockRequests[0]);
        self::assertStringContainsString('Entre semana, nada muy elaborado.', $prompt);
        self::assertStringContainsString('1. Cocido completo | 180 min | 1 steps | chickpeas, beef', $prompt);

        /** @var list<array{title: string, notesFit?: array{fit: string, when: string, reason: array{es: string, en: string}|null}}> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        $fits = array_column($recipes, 'notesFit', 'title');
        // (assertEquals: jsonb keeps object keys in its own order.)
        self::assertEquals(['fit' => 'poor', 'when' => 'weekdays', 'reason' => ['es' => 'Laboriosa para entre semana', 'en' => 'Fiddly for a weekday']], $fits['Cocido completo']);
        self::assertSame('good', $fits['Tortilla francesa']['fit']);
        self::assertArrayNotHasKey('Ensalada', $fits, 'left out by the model: neutral, nothing to say');

        $impact = $this->profile()['impact'];
        self::assertSame([1, 1, 0], [$impact['notesGood'], $impact['notesPoor'], $impact['notesPending']]);
        self::assertCount(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'notes_fit']));

        $this->judgeNotes();
        self::assertCount(1, $this->bedrockRequests, 'nothing left to judge: no call');

        // A recipe that changes is judged again; new notes, the whole library.
        $this->client->request('PATCH', '/api/recipes/'.$this->recipeId('Ensalada'), ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'json' => ['title' => 'Ensalada de pasta']]);
        self::assertCount(1, $this->queued(JudgeNotes::class));
        $this->save([], notes: 'Los niños no comen picante.');
        self::assertSame(3, $this->profile()['impact']['notesPending']);
        /** @var list<array<string, mixed>> $recipes */
        $recipes = $this->client->request('GET', '/api/recipes')->toArray();
        self::assertArrayNotHasKey('notesFit', $recipes[0], 'judged against other notes: stale');
    }

    public function testThePlannerFollowsTheNotes(): void
    {
        foreach (range(1, 10) as $i) {
            $this->recipe("Rápida $i", [], keys: ['onion', "veg_$i"]);
        }
        foreach (range(1, 3) as $i) {
            $this->recipe("Laboriosa $i", [], keys: ['onion', "slow_$i"]);
        }
        $notes = 'Entre semana, nada muy elaborado.';
        $this->save([], notes: $notes);
        $hash = Taste::notesHash($notes);
        self::assertNotNull($hash);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($entityManager->getRepository(Recipe::class)->findAll() as $recipe) {
            $slow = str_starts_with($recipe->getTitle(), 'Laboriosa');
            $recipe->judgeNotes($hash, $recipe->factsFingerprint(), $slow ? 'poor' : 'neutral', $slow ? 'weekdays' : 'always', $slow ? ['es' => 'Laboriosa', 'en' => 'Fiddly'] : null);
        }
        $entityManager->flush();

        // Monday to Friday, lunch and dinner: ten meals, ten quick recipes.
        foreach (range(1, 3) as $_) {
            $plan = $this->createPlan(10);
            $titles = array_map(static fn (array $e): string => $e['recipe']['title'] ?? '', $plan['entries']);
            self::assertSame([], array_filter($titles, static fn (string $t): bool => str_starts_with($t, 'Laboriosa')), 'nothing fiddly on weekdays while there is something else');
            $this->client->request('DELETE', '/api/meal-plans/'.$plan['id']);
        }
    }

    // —— Helpers ————————————————————————————————————————————————

    /**
     * @param list<FoodGroup>|null $groups   null: facts not read yet
     * @param list<FoodGroup>      $optional
     * @param list<string>         $keys
     * @param list<string>         $tags
     */
    private function recipe(string $title, ?array $groups, ?Heaviness $heaviness = Heaviness::Medium, array $optional = [], array $keys = ['onion'], array $tags = [], ?int $minutes = null): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $this->ana->getId());
        self::assertNotNull($owner);

        $recipe = new Recipe();
        $recipe->assignHousehold($owner->getHousehold());
        $recipe->setTitle($title);
        $recipe->setMealTypes([MealType::Lunch, MealType::Dinner]);
        $recipe->setTags($tags);
        $recipe->setCookMinutes($minutes);
        $recipe->setSteps(['Cocinar.']);
        $recipe->setIngredients(array_map(static function (string $key): RecipeIngredient {
            $ingredient = new RecipeIngredient();
            $ingredient->setRawText($key);
            $ingredient->setCanonicalKey($key);

            return $ingredient;
        }, $keys));
        if (null !== $groups) {
            $recipe->applyFacts(new RecipeFacts($heaviness ?? Heaviness::Medium, 0, $groups, $optional), $recipe->factsFingerprint());
        }
        $entityManager->persist($recipe);
        $entityManager->flush();
    }

    /** @return array{impact: array{notesGood: ?int, notesPoor: ?int, notesPending: ?int}} */
    private function profile(): array
    {
        /** @var array{impact: array{notesGood: ?int, notesPoor: ?int, notesPending: ?int}} $profile */
        $profile = $this->client->request('GET', '/api/taste-profile')->toArray();

        return $profile;
    }

    private function judgeNotes(): void
    {
        $household = $this->ana->getHousehold()->getId()->toRfc4122();
        self::getContainer()->get(JudgeNotesHandler::class)(new JudgeNotes($household));
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param class-string $class
     *
     * @return list<object> messages of that class queued since the last call
     */
    private function queued(string $class): array
    {
        $transport = $this->asyncTransport();
        $messages = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $m): bool => $m instanceof $class));
        $transport->reset();

        return $messages;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @param list<array<string, mixed>> $judgements */
    private function judgements(array $judgements): JsonMockResponse
    {
        return new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => ['toolUseId' => 't1', 'name' => NotesJudge::TOOL, 'input' => ['judgements' => $judgements]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 800, 'outputTokens' => 200],
        ]);
    }

    private function recipeId(string $title): string
    {
        $recipe = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Recipe::class)->findOneBy(['title' => $title]);
        self::assertNotNull($recipe);

        return $recipe->getId()->toRfc4122();
    }

    /**
     * @param array<string, mixed>               $rules
     * @param array{es: string, en: string}|null $summary
     *
     * @return array{rules: array{excludeGroups: list<array{group: string, strength: string}>}, updatedBy: string, summary: array{es: string, en: string}, impact: array{unread: int}}
     */
    private function save(array $rules, ?array $summary = null, ?string $notes = null): array
    {
        $response = $this->client->request('PUT', '/api/taste-profile', ['json' => ['answers' => ['diet' => null], 'rules' => $rules, 'notes' => $notes, 'summary' => $summary]]);
        self::assertResponseIsSuccessful();
        /** @var array{rules: array{excludeGroups: list<array{group: string, strength: string}>}, updatedBy: string, summary: array{es: string, en: string}, impact: array{unread: int}} $profile */
        $profile = $response->toArray();

        return $profile;
    }

    /**
     * @param array<string, mixed> $answers
     *
     * @return array{rules: array<string, mixed>&array{excludeGroups: list<array<string, string>>, excludeIngredients: list<array{name: array{es: string}}>, mealHeaviness: array<string, ?string>, diet: ?string, maxSpice: ?int}, notes: ?string, summary: array{es: string, en: string}|null, questions: list<array<string, mixed>>, fallback: bool, impact: array{library: int, allowed: int, excludeGroups: list<int>, excludeIngredients: list<int>}}
     */
    private function draft(array $answers): array
    {
        $response = $this->client->request('POST', '/api/taste-profile/draft', ['json' => ['answers' => $answers]]);
        self::assertResponseIsSuccessful();
        /** @var array{rules: array<string, mixed>&array{excludeGroups: list<array<string, string>>, excludeIngredients: list<array{name: array{es: string}}>, mealHeaviness: array<string, ?string>, diet: ?string, maxSpice: ?int}, notes: ?string, summary: array{es: string, en: string}|null, questions: list<array<string, mixed>>, fallback: bool, impact: array{library: int, allowed: int, excludeGroups: list<int>, excludeIngredients: list<int>}} $draft */
        $draft = $response->toArray();

        return $draft;
    }

    /** @return Plan */
    private function createPlan(int $meals = 14): array
    {
        $slots = [];
        foreach (range(0, 6) as $day) {
            foreach (['lunch', 'dinner'] as $slot) {
                $slots[] = ['date' => (new \DateTimeImmutable(self::WEEK))->modify("+$day days")->format('Y-m-d'), 'slot' => $slot];
            }
        }
        $response = $this->client->request('POST', '/api/meal-plans', ['json' => ['weekStart' => self::WEEK, 'slots' => \array_slice($slots, 0, $meals)]]);
        self::assertResponseStatusCodeSame(201);

        return self::plan($response->toArray());
    }

    /**
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
     * @param array<string, mixed>                                 $rules
     * @param list<array{question: string, options: list<string>}> $questions
     */
    private function answer(array $rules, array $questions = []): JsonMockResponse
    {
        return new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['toolUse' => [
                'toolUseId' => 't1',
                'name' => TasteCompiler::TOOL,
                'input' => [
                    'rules' => $rules,
                    'notes' => 'Los niños no comen nada con salsa.',
                    'summary' => ['es' => 'Coméis de todo menos pescado.', 'en' => 'You eat everything but fish.'],
                    'questions' => $questions,
                ],
            ]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 1500, 'outputTokens' => 400],
        ]);
    }

    /** @param list<MockResponse> $responses */
    private function mockBedrock(array $responses): void
    {
        $this->bedrockRequests = [];
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true);
            $this->bedrockRequests[] = \is_array($body) ? $body : [];

            return array_shift($responses) ?? throw new \LogicException('Unexpected Bedrock call to '.$url);
        });
    }

    /** @param array<mixed> $request */
    private function system(array $request): string
    {
        return self::at($request, 'system', 0, 'text');
    }

    /** @param array<mixed> $request */
    private function userText(array $request): string
    {
        return self::at($request, 'messages', 0, 'content', 0, 'text');
    }

    /** @param array<mixed> $data */
    private static function at(array $data, string|int ...$path): string
    {
        $value = $data;
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return \is_string($value) ? $value : '';
    }
}
