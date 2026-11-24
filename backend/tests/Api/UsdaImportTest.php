<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use App\Entity\Food;
use App\Entity\User;
use App\Enum\FoodSource;
use App\Food\FoodCatalog;
use App\Food\FoodFacts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class UsdaImportTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = true;

    private User $ana;
    /** @var list<string> USDA URLs asked */
    private array $asked = [];

    protected function setUp(): void
    {
        self::bootKernel();
        (new Filesystem())->remove(self::getContainer()->getParameter('app.usda_dir'));
        $this->ana = $this->createUser('ana@example.com');
    }

    public function testWhatBedcaLacksComesFromUsdaGenericFoodsFirst(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(FoodCatalog::class)->ensure($this->ana->getHousehold(), [
            'beef_broth' => ['name' => 'beef broth', 'language' => 'en'],
            'gochujang' => ['name' => 'gochujang', 'language' => 'en'],
            'lentils' => ['name' => 'lentils', 'language' => 'en'],
        ]);
        foreach ($entityManager->getRepository(Food::class)->findAll() as $food) {
            $food->describe(new FoodFacts(null, 'beef_broth' === $food->getKey() ? 'beef broth' : null, 'gochujang' === $food->getKey() ? 220 : 5, 1, 0, 0, 0.5, 0, 0, 0.8, [], [], 0, null, null));
        }
        $lentils = $entityManager->getRepository(Food::class)->findOneBy(['key' => 'lentils']);
        self::assertNotNull($lentils);
        $lentils->adopt(new FoodFacts(null, null, 309, 23, 2, 0.3, 48, 2, 11, 0, [], [], 0, null, null), FoodSource::Bedca, '1065 Lenteja, seca, cruda', []);
        $entityManager->flush();
        $this->mock();

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:foods:usda'));
        $tester->execute(['--apply' => true]);
        $output = $tester->getDisplay();
        self::assertStringNotContainsString('lentils', $output, 'from BEDCA already');
        self::assertStringContainsString('2 food(s) now from USDA', $output);

        $entityManager->clear();
        $broth = $entityManager->getRepository(Food::class)->findOneBy(['key' => 'beef_broth']);
        $gochujang = $entityManager->getRepository(Food::class)->findOneBy(['key' => 'gochujang']);
        self::assertNotNull($broth);
        self::assertNotNull($gochujang);
        self::assertSame([FoodSource::Usda, '172923 Soup, beef broth, cubed, prepared with water (SR Legacy)', 3.0, 0.65], [$broth->getSource(), $broth->getSourceRef(), $broth->getKcal(), $broth->getSalt()]);
        self::assertSame('2244609 GOCHUJANG SAUCE, GOCHUJANG (Branded)', $gochujang->getSourceRef(), 'no generic food: a product\'s label');
        self::assertSame([
            'POST https://api.nal.usda.gov/fdc/v1/foods/search?api_key=DEMO_KEY beef broth in Foundation,SR Legacy',
            'POST https://api.nal.usda.gov/fdc/v1/foods/search?api_key=DEMO_KEY beef broth in Survey (FNDDS)',
            'POST https://api.nal.usda.gov/fdc/v1/foods/search?api_key=DEMO_KEY gochujang in Foundation,SR Legacy',
            'POST https://api.nal.usda.gov/fdc/v1/foods/search?api_key=DEMO_KEY gochujang in Survey (FNDDS)',
            'POST https://api.nal.usda.gov/fdc/v1/foods/search?api_key=DEMO_KEY gochujang in Branded',
        ], $this->asked, 'raw ingredients and prepared foods apart, by key in words, as JSON; labels only when both have nothing');
    }

    private function mock(): void
    {
        $usda = self::getContainer()->get('usda.http_client');
        self::assertInstanceOf(MockHttpClient::class, $usda);
        $usda->setResponseFactory(function (string $method, string $url, array $options): JsonMockResponse {
            /** @var array{query?: string, dataType?: list<string>} $body */
            $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '{}', true) ?? [];
            $types = implode(',', $body['dataType'] ?? []);
            $this->asked[] = $method.' '.urldecode($url).' '.($body['query'] ?? '').' in '.$types;
            $nutrient = static fn (string $n, float $v, string $u = 'G'): array => ['nutrientNumber' => $n, 'value' => $v, 'unitName' => $u];
            $foods = [];
            if ('beef broth' === ($body['query'] ?? null) && 'Foundation,SR Legacy' === $types) {
                $foods = [
                    ['fdcId' => 171561, 'description' => 'Soup, beef broth, cubed, dry', 'dataType' => 'SR Legacy', 'foodNutrients' => [$nutrient('208', 170, 'KCAL'), $nutrient('203', 17.3), $nutrient('204', 4), $nutrient('205', 16.1)]],
                    ['fdcId' => 172923, 'description' => 'Soup, beef broth, cubed, prepared with water', 'dataType' => 'SR Legacy', 'foodNutrients' => [$nutrient('208', 3, 'KCAL'), $nutrient('203', 0.21), $nutrient('204', 0.08), $nutrient('205', 0.25), $nutrient('307', 260, 'MG')]],
                ];
            } elseif ('gochujang' === ($body['query'] ?? null) && 'Branded' === $types) {
                $foods = [['fdcId' => 2244609, 'description' => 'GOCHUJANG SAUCE, GOCHUJANG', 'dataType' => 'Branded', 'foodNutrients' => [$nutrient('208', 267, 'KCAL'), $nutrient('203', 3.33), $nutrient('204', 5), $nutrient('205', 53.3), $nutrient('269', 43.3), $nutrient('307', 967, 'MG')]]];
            }

            return new JsonMockResponse(['totalHits' => \count($foods), 'foods' => $foods]);
        });

        $bedrock = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $bedrock);
        $bedrock->setResponseFactory([new JsonMockResponse([
            'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_matches', 'input' => ['matches' => [
                ['key' => 'beef_broth', 'pick' => 'b'],
                ['key' => 'gochujang', 'pick' => 'GOCHUJANG SAUCE, GOCHUJANG [Branded]'],
            ]]]]]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 100, 'outputTokens' => 50],
        ])]);
    }

    public function testARefusedSearchStopsRatherThanFindingNothing(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(FoodCatalog::class)->ensure($this->ana->getHousehold(), ['beef_broth' => ['name' => 'beef broth', 'language' => 'en']]);
        foreach ($entityManager->getRepository(Food::class)->findAll() as $food) {
            $food->describe(new FoodFacts(null, null, 5, 1, 0, 0, 0.5, 0, 0, 0.8, [], [], 0, null, null));
        }
        $entityManager->flush();
        $usda = self::getContainer()->get('usda.http_client');
        self::assertInstanceOf(MockHttpClient::class, $usda);
        $usda->setResponseFactory([new JsonMockResponse(['error' => 'bad request'], ['http_code' => 400])]);

        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException()))->find('app:foods:usda'));
        $this->expectExceptionMessage('USDA refused POST /foods/search (HTTP 400).');
        $tester->execute(['--apply' => true]);
    }
}
