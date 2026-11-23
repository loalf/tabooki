<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Command\FoodsCommand;
use App\Entity\ExtractionRun;
use App\Entity\Food;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\Allergen;
use App\Enum\Diet;
use App\Enum\FoodGroup;
use App\Enum\FoodSource;
use App\Enum\Language;
use App\Food\FoodCatalog;
use App\Food\FoodFacts;
use App\Message\DescribeFoods;
use App\MessageHandler\DescribeFoodsHandler;
use App\Shopping\IngredientCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FoodCatalogTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        $this->ana = $this->createUser('ana@example.com');
        $this->signIn($this->client, $this->ana);
    }

    public function testASavedRecipeBringsItsIngredientsIntoTheCatalog(): void
    {
        $recipe = $this->recipe('Garbanzos con chorizo', [
            ['400 g de garbanzos cocidos', 'garbanzos cocidos', 'chickpeas_cooked'],
            ['1 chorizo', 'chorizo', 'chorizo'],
            ['un poco de amor', null, null],
        ]);

        $this->client->request('PATCH', '/api/recipes/'.$recipe->getId()->toRfc4122(), [
            'json' => ['title' => 'Garbanzos con chorizo picante'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(['chickpeas_cooked', 'chorizo'], array_map(static fn (Food $f): string => $f->getKey(), $this->foods()), 'not the line typed by hand');
        $chorizo = $this->food('chorizo');
        self::assertSame('chorizo', $chorizo->getNameEs(), 'named as the recipe had it until described');
        self::assertFalse($chorizo->isDescribed());
        self::assertCount(1, $this->queued(DescribeFoods::class));

        $this->client->request('PATCH', '/api/recipes/'.$recipe->getId()->toRfc4122(), [
            'json' => ['title' => 'Garbanzos con chorizo'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertSame([], $this->queued(DescribeFoods::class), 'nothing new, nothing to describe');
    }

    public function testDescribingFillsInTheFoodsAndKeepsFixesMadeByHand(): void
    {
        $this->recipe('Garbanzos con chorizo', [
            ['400 g de garbanzos cocidos', 'garbanzos cocidos', 'chickpeas_cooked'],
            ['1 chorizo', 'chorizo', 'chorizo'],
            ['1 guindilla', 'guindilla', 'chili_pepper'],
        ], save: true);
        $this->food('chili_pepper')->correct(self::facts(kcal: 40, spice: 2));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->mockBedrock(new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_foods', 'input' => ['foods' => [
                self::answer('chickpeas_cooked', 'garbanzos cocidos', 'cooked chickpeas', kcal: 139, protein: 7.2, carbs: 16.4, sugar: 0.4, density: null, gramsPerPiece: null),
                // Sugar above carbs, a negative fibre and a made-up allergen: kept within bounds.
                self::answer('chorizo', 'chorizo', 'chorizo', kcal: 455, protein: 24, fat: 38, carbs: 2, sugar: 5, fiber: -1, groups: ['pork', 'processed_meat'], allergens: ['milk', 'nonsense'], spice: 1, gramsPerPiece: 100, density: null),
                self::answer('chili_pepper', 'guindilla', 'chili pepper', kcal: 999, spice: 0),
            ]]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 900, 'outputTokens' => 700],
        ]));

        $queued = \count($this->queued(DescribeFoods::class));
        (self::getContainer()->get(DescribeFoodsHandler::class))(new DescribeFoods($this->ana->getHousehold()->getId()->toRfc4122()));

        $chickpeas = $this->food('chickpeas_cooked');
        self::assertTrue($chickpeas->isDescribed());
        self::assertSame(FoodSource::Llm, $chickpeas->getSource());
        self::assertSame([139.0, 7.2, 16.4, 0.4], [$chickpeas->getKcal(), $chickpeas->getProtein(), $chickpeas->getCarbs(), $chickpeas->getSugar()]);
        self::assertSame(['cooked chickpeas', 'garbanzos cocidos'], [$chickpeas->name('en'), $chickpeas->name('es')]);
        self::assertSame(Diet::Vegan, $chickpeas->getDiet());

        $chorizo = $this->food('chorizo');
        self::assertSame(2.0, $chorizo->getSugar(), 'no more sugar than carbs');
        self::assertSame(0.0, $chorizo->getFiber());
        self::assertSame([FoodGroup::Pork, FoodGroup::ProcessedMeat], $chorizo->getFoodGroups());
        self::assertSame([Allergen::Milk], $chorizo->getAllergens());
        self::assertSame([1, 100.0, null], [$chorizo->getSpice(), $chorizo->getGramsPerPiece(), $chorizo->getDensity()]);
        self::assertNull($chorizo->getDiet());

        $chili = $this->food('chili_pepper');
        self::assertSame([40.0, 2, FoodSource::Manual], [$chili->getKcal(), $chili->getSpice(), $chili->getSource()], 'the model does not undo a fix');

        $runs = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ExtractionRun::class)->findBy(['step' => 'foods']);
        self::assertCount(1, $runs, 'the call is logged for the costs page');
        self::assertSame([900, 700, true], [$runs[0]->getInputTokens(), $runs[0]->getOutputTokens(), $runs[0]->isOk()]);
        self::assertCount($queued, $this->queued(DescribeFoods::class), 'nothing left: no next batch');
    }

    public function testDescribingGoesOnBatchAfterBatchButDoesNotLoop(): void
    {
        $household = $this->ana->getHousehold();
        $seen = [];
        for ($i = 0; $i < 41; ++$i) {
            $seen[\sprintf('food_%02d', $i)] = ['name' => 'alimento '.$i, 'language' => 'es'];
        }
        self::getContainer()->get(FoodCatalog::class)->ensure($household, $seen);
        $handler = self::getContainer()->get(DescribeFoodsHandler::class);
        $toolAnswer = static fn (array $foods): JsonMockResponse => new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_foods', 'input' => ['foods' => $foods]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 10, 'outputTokens' => 10],
        ]);

        $queued = \count($this->queued(DescribeFoods::class));
        $this->mockBedrock($toolAnswer([self::answer('food_00', 'alimento', 'food', kcal: 100)]));
        $handler(new DescribeFoods($household->getId()->toRfc4122()));
        self::assertCount($queued + 1, $this->queued(DescribeFoods::class), 'one described, more waiting: the next batch');

        $this->mockBedrock($toolAnswer([]));
        $handler(new DescribeFoods($household->getId()->toRfc4122()));
        self::assertCount($queued + 1, $this->queued(DescribeFoods::class), 'none described: stop rather than ask again for the same ones');
    }

    public function testAHouseholdIngredientPointsAtItsFoodAndCanBePointedElsewhere(): void
    {
        $catalog = self::getContainer()->get(IngredientCatalog::class);
        $ingredients = $catalog->forKeys($this->ana->getHousehold(), [
            'chickpeas_cooked' => ['name' => 'garbanzos cocidos', 'language' => 'es'],
            'chickpeas_canned' => ['name' => 'garbanzos de bote', 'language' => 'es'],
        ]);
        $canned = $ingredients['chickpeas_canned'];
        self::assertSame('chickpeas_canned', $canned->getFood()?->getKey(), 'the food with the same key, added first');
        $cooked = $this->food('chickpeas_cooked');

        $read = $this->client->request('GET', '/api/foods/'.$cooked->getId()->toRfc4122())->toArray();
        self::assertSame(['chickpeas_cooked', 'garbanzos cocidos', false], [$read['key'], $read['nameEs'], $read['described']]);

        $this->client->request('PATCH', '/api/ingredients/'.$canned->getId()->toRfc4122(), [
            'json' => ['food' => '/api/foods/'.$cooked->getId()->toRfc4122()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('chickpeas_cooked', $entityManager->find(Ingredient::class, $canned->getId())?->getFood()?->getKey());
    }

    public function testTheCommandFillsTheCatalogFromExistingRecipes(): void
    {
        $this->recipe('Lentejas', [['300 g de lentejas', 'lentejas', 'lentils']]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // A catalog ingredient from before foods existed: pointing nowhere.
        $entityManager->persist($old = new Ingredient($this->ana->getHousehold(), 'lentils'));
        $entityManager->flush();

        $application = new Application();
        $application->addCommand(self::getContainer()->get(FoodsCommand::class));
        $tester = new CommandTester($application->find('app:foods'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 new food(s), 1 catalog ingredient(s) linked', $tester->getDisplay());
        $entityManager->clear();
        self::assertSame('lentils', $entityManager->find(Ingredient::class, $old->getId())?->getFood()?->getKey());
        self::assertCount(1, $this->queued(DescribeFoods::class));
    }

    /** @param list<array{0: string, 1: string|null, 2: string|null}> $lines raw, name, key */
    private function recipe(string $title, array $lines, bool $save = false): Recipe
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $entityManager->find(User::class, $this->ana->getId());
        self::assertNotNull($owner);

        $recipe = new Recipe();
        $recipe->assignHousehold($owner->getHousehold());
        $recipe->setTitle($title);
        $recipe->setLanguage(Language::Es);
        $recipe->setServings(4);
        $recipe->setIngredients(array_map(static function (array $line): RecipeIngredient {
            $ingredient = new RecipeIngredient();
            $ingredient->setRawText($line[0]);
            $ingredient->setName($line[1]);
            $ingredient->setCanonicalKey($line[2]);

            return $ingredient;
        }, $lines));
        $entityManager->persist($recipe);
        $entityManager->flush();
        if ($save) {
            self::getContainer()->get(FoodCatalog::class)->recipeChanged($recipe);
        }

        return $recipe;
    }

    /** @return list<Food> */
    private function foods(): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Food::class)->findBy([], ['key' => 'ASC']);
    }

    private function food(string $key): Food
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $food = $entityManager->getRepository(Food::class)->findOneBy(['key' => $key]);
        self::assertNotNull($food);
        $entityManager->refresh($food);

        return $food;
    }

    private static function facts(float $kcal, int $spice): FoodFacts
    {
        return new FoodFacts(null, null, $kcal, 2, 0.4, 0, 9, 5, 1.5, 0, [], [], $spice, 15, null);
    }

    /**
     * @param list<string> $groups
     * @param list<string> $allergens
     *
     * @return array<string, mixed>
     */
    private static function answer(
        string $key, string $es, string $en, float $kcal, float $protein = 0, float $fat = 0, float $carbs = 0, float $sugar = 0, float $fiber = 0,
        array $groups = [], array $allergens = [], int $spice = 0, ?float $gramsPerPiece = null, ?float $density = 1,
    ): array {
        return [
            'key' => $key, 'name_es' => $es, 'name_en' => $en, 'kcal' => $kcal, 'protein' => $protein, 'fat' => $fat, 'saturated_fat' => 0,
            'carbs' => $carbs, 'sugar' => $sugar, 'fiber' => $fiber, 'salt' => 0, 'food_groups' => $groups, 'allergens' => $allergens,
            'spice' => $spice, 'grams_per_piece' => $gramsPerPiece, 'density' => $density,
        ];
    }

    private function mockBedrock(JsonMockResponse $response): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([$response]);
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function queued(string $class): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());

        return array_values(array_filter($messages, static fn (object $m): bool => $m instanceof $class));
    }
}
