<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use App\Command\FoodsMergeCommand;
use App\Entity\Food;
use App\Entity\Household;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\ShoppingList;
use App\Entity\TasteProfile;
use App\Enum\Aisle;
use App\Enum\Language;
use App\Food\FoodFacts;
use App\Food\KeyMerger;
use App\Taste\TasteRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class FoodKeysTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = false;

    private EntityManagerInterface $entityManager;
    private Household $household;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->household = $this->createUser('ana@example.com')->getHousehold();
        $this->household = $this->entityManager->find(Household::class, $this->household->getId()) ?? $this->household;
    }

    public function testMergingAKeyRewritesItEverywhere(): void
    {
        $this->recipe('Judiones', [['ajo', 'ajo'], ['cebolla', 'cebolla']]);
        $this->recipe('Lentils', [['garlic', 'garlic']]);
        $this->food('ajo', kcal: 150);
        $garlic = $this->food('garlic', kcal: 149);
        $ajo = new Ingredient($this->household, 'ajo');
        $ajo->setAisle(Aisle::Produce);
        $this->entityManager->persist($ajo);
        $this->entityManager->persist(new Ingredient($this->household, 'cebolla'));
        $this->entityManager->persist(new ShoppingList($this->household, new \DateTimeImmutable('2026-11-23'), ['ajo', 'cebolla', 'item:1']));
        $taste = new TasteProfile($this->household);
        $taste->update([], TasteRules::fromArray(['excludeIngredients' => [['keys' => ['ajo', 'garlic_powder'], 'name' => ['es' => 'ajo', 'en' => 'garlic'], 'strength' => 'avoid']]]), null, null, null);
        $this->entityManager->persist($taste);
        $this->entityManager->flush();

        $merger = self::getContainer()->get(KeyMerger::class);
        $changed = $merger->merge('ajo', 'garlic');
        self::assertSame(['recipeLines' => 1, 'ingredients' => 1, 'lists' => 1, 'tastes' => 1], $changed);

        self::assertSame(['cebolla', 'garlic', 'garlic'], $this->keysInRecipes());
        self::assertNull($this->entityManager->getRepository(Food::class)->findOneBy(['key' => 'ajo']), 'the duplicate food goes');
        $ingredient = $this->entityManager->getRepository(Ingredient::class)->findOneBy(['canonicalKey' => 'garlic']);
        self::assertNotNull($ingredient);
        self::assertSame([Aisle::Produce, $garlic->getId()->toRfc4122()], [$ingredient->getAisle(), $ingredient->getFood()?->getId()->toRfc4122()], 'renamed, fixes kept, pointing at the target food');
        $list = $this->entityManager->getRepository(ShoppingList::class)->findOneBy([]);
        self::assertEqualsCanonicalizing(['garlic', 'cebolla', 'item:1'], $list?->getChecked());
        $rules = $this->entityManager->getRepository(TasteProfile::class)->findOneBy([])?->getRules();
        self::assertSame(['garlic', 'garlic_powder'], $rules?->excludeIngredients[0]->keys);
        self::assertSame('ajo', $rules->excludeIngredients[0]->name->es, 'names are not keys');

        // Into a key nobody has yet: the food is renamed and keeps its description.
        $this->food('cebolla', kcal: 40);
        $merger->merge('cebolla', 'onion');
        $onion = $this->entityManager->getRepository(Food::class)->findOneBy(['key' => 'onion']);
        self::assertSame(40.0, $onion?->getKcal());
        self::assertSame('onion', $this->entityManager->getRepository(Ingredient::class)->findOneBy(['canonicalKey' => 'onion'])?->getFood()?->getKey());
    }

    public function testWhenAHouseholdHasBothKeysItKeepsTheTargetsEntry(): void
    {
        $this->recipe('Mix', [['cherry tomatoes', 'cherry_tomatoes'], ['cherry tomato', 'cherry_tomato']]);
        $plural = new Ingredient($this->household, 'cherry_tomatoes');
        $singular = new Ingredient($this->household, 'cherry_tomato');
        $singular->setStaple(true);
        $this->entityManager->persist($plural);
        $this->entityManager->persist($singular);
        $this->entityManager->flush();

        self::getContainer()->get(KeyMerger::class)->merge('cherry_tomatoes', 'cherry_tomato');

        $left = $this->entityManager->getRepository(Ingredient::class)->findBy(['household' => $this->household]);
        self::assertCount(1, $left);
        self::assertTrue($left[0]->isStaple());
        self::assertSame(['cherry_tomato', 'cherry_tomato'], $this->keysInRecipes());
    }

    public function testTheModelReviewsTheCatalogAndMergesOnlyWhenAsked(): void
    {
        $this->recipe('Judiones', [['ajo', 'ajo'], ['nata', 'nata_para_cocinar']]);
        $this->food('ajo', kcal: 150);
        $this->food('garlic', kcal: 149);
        $this->food('nata_para_cocinar', kcal: 190);
        $this->mockBedrock([
            ['from' => 'ajo', 'to' => 'garlic'],
            ['from' => 'nata_para_cocinar', 'to' => 'Cooking Cream'],
            ['from' => 'unknown_key', 'to' => 'garlic'],
            ['from' => 'garlic', 'to' => 'garlic'],
        ]);

        $tester = $this->command();
        $tester->setInputs(['no']);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 merge(s) proposed, none made', $tester->getDisplay());
        self::assertSame(['ajo', 'nata_para_cocinar'], $this->keysInRecipes(), 'only listed');

        $this->mockBedrock([['from' => 'ajo', 'to' => 'garlic'], ['from' => 'nata_para_cocinar', 'to' => 'cooking_cream']]);
        $tester->execute(['--apply' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(['cooking_cream', 'garlic'], $this->keysInRecipes());

        $tester->execute(['from' => 'cooking_cream', 'to' => 'cream']);
        self::assertStringContainsString('cooking_cream → cream: 1 recipe line(s)', $tester->getDisplay());
    }

    /** @param list<array{0: string, 1: string}> $lines name, key */
    private function recipe(string $title, array $lines): void
    {
        $recipe = new Recipe();
        $recipe->assignHousehold($this->household);
        $recipe->setTitle($title);
        $recipe->setLanguage(Language::Es);
        $recipe->setServings(4);
        $recipe->setIngredients(array_map(static function (array $line): RecipeIngredient {
            $ingredient = new RecipeIngredient();
            $ingredient->setRawText($line[0]);
            $ingredient->setName($line[0]);
            $ingredient->setCanonicalKey($line[1]);

            return $ingredient;
        }, $lines));
        $this->entityManager->persist($recipe);
        $this->entityManager->flush();
    }

    private function food(string $key, float $kcal): Food
    {
        $food = new Food($key);
        $food->describe(new FoodFacts(null, null, $kcal, 1, 1, 0, 1, 0, 0, 0, [], [], 0, null, null));
        $this->entityManager->persist($food);
        $this->entityManager->flush();

        return $food;
    }

    /** @return list<string> */
    private function keysInRecipes(): array
    {
        return array_values(array_filter($this->entityManager->getConnection()->fetchFirstColumn('SELECT canonical_key FROM recipe_ingredient ORDER BY canonical_key'), \is_string(...)));
    }

    private function command(): CommandTester
    {
        $application = new Application();
        $application->addCommand(self::getContainer()->get(FoodsMergeCommand::class));

        return new CommandTester($application->find('app:foods:merge'));
    }

    /** @param list<array{from: string, to: string}> $merges */
    private function mockBedrock(array $merges): void
    {
        $httpClient = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_merges', 'input' => ['merges' => $merges]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 100, 'outputTokens' => 20],
        ])]);
    }
}
