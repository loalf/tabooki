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
use Symfony\Component\HttpClient\Response\MockResponse;

final class BedcaImportTest extends ApiTestCase
{
    use SignsInUsers;

    protected static ?bool $alwaysBootKernel = true;

    private User $ana;
    /** @var list<string> what BEDCA was asked */
    private array $asked = [];
    private int $matchCalls = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        (new Filesystem())->remove(self::getContainer()->getParameter('app.bedca_dir'));
        $this->ana = $this->createUser('ana@example.com');
    }

    public function testBedcasFiguresReplaceTheEstimatesOnceShownAndConfirmed(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(FoodCatalog::class)->ensure($this->ana->getHousehold(), [
            'cooking_cream' => ['name' => 'nata para cocinar', 'language' => 'es'],
            'chickpeas_cooked' => ['name' => 'garbanzos cocidos', 'language' => 'es'],
            'gochujang' => ['name' => 'gochujang', 'language' => 'es'],
            'parsley' => ['name' => 'perejil', 'language' => 'es'],
        ]);
        foreach ($entityManager->getRepository(Food::class)->findAll() as $food) {
            $food->describe(new FoodFacts(null, null, 70, 2, 7, 4, 3, 3, 0, 0.1, [], [], 0, null, 1.0));
        }
        $parsley = $entityManager->getRepository(Food::class)->findOneBy(['key' => 'parsley']);
        self::assertNotNull($parsley);
        $parsley->correct(new FoodFacts(null, null, 36, 3, 1, 0, 6, 1, 3, 0.1, [], [], 0, null, null));
        $entityManager->flush();
        $this->mockServices();

        // Shown, not confirmed: nothing changes, and the pairs are kept.
        $tester = $this->command();
        $tester->setInputs(['no']);
        $tester->execute([]);
        $output = $tester->getDisplay();
        self::assertStringContainsString('923 Nata, líquida, para cocinar', $output);
        self::assertStringContainsString('energy 359 kcal, but its nutrients add up to 160', $output, 'an entry that contradicts itself is left out');
        self::assertStringContainsString('far from the estimate', $output, 'cream: 70 now, 201 in BEDCA');
        self::assertStringNotContainsString('parsley', $output, 'fixed by hand: left alone');
        self::assertSame(70.0, $this->food('cooking_cream')->getKcal());

        // Named, it's imported: BEDCA's figures, what it lacks still estimated, the pair not asked again.
        $tester = $this->command();
        $tester->execute(['keys' => ['cooking_cream'], '--apply' => true]);
        self::assertSame(1, $this->matchCalls, 'the saved pairs are reused');
        $cream = $this->food('cooking_cream');
        self::assertSame([FoodSource::Bedca, '923 Nata, líquida, para cocinar, 18% de grasa', 200.8, 0.11, ['fiber']], [
            $cream->getSource(), $cream->getSourceRef(), $cream->getKcal(), $cream->getSalt(), $cream->getEstimatedFields(),
        ]);
        self::assertSame(0.0, $cream->getFiber() ?? -1.0, 'the estimate, kept');
        self::assertSame(1.0, $cream->getDensity(), 'weights and allergens aren\'t BEDCA\'s: kept');
        self::assertCount(2, array_filter($this->asked, static fn (string $q): bool => str_contains($q, 'level="2"')), 'each food asked once, then from the saved answers');

        // A pairing set by hand.
        $tester = $this->command();
        $tester->execute(['keys' => ['chickpeas_cooked'], '--pair' => ['chickpeas_cooked=0'], '--apply' => true]);
        self::assertStringContainsString('no BEDCA food', $tester->getDisplay());
    }

    private function food(string $key): Food
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $food = $entityManager->getRepository(Food::class)->findOneBy(['key' => $key]);
        self::assertNotNull($food);

        return $food;
    }

    private function command(): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel'));

        return new CommandTester($application->find('app:foods:bedca'));
    }

    private function mockServices(): void
    {
        $bedca = self::getContainer()->get('bedca.http_client');
        self::assertInstanceOf(MockHttpClient::class, $bedca);
        $bedca->setResponseFactory(function (string $method, string $url, array $options): MockResponse {
            $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
            $this->asked[] = $body;
            if (str_contains($body, 'level="1"')) {
                return new MockResponse('<?xml version="1.0" encoding="utf-8"?><foodresponse>'
                    .'<food><f_id>923</f_id><f_ori_name>Nata, líquida, para cocinar, 18% de grasa</f_ori_name><f_eng_name>Cream, 18 % fat, uht</f_eng_name></food>'
                    .'<food><f_id>2192</f_id><f_ori_name>Garbanzo, hervido</f_ori_name><f_eng_name>Chickpea, boiled</f_eng_name></food>'
                    .'</foodresponse>');
            }
            $value = static fn (string $code, string $amount, string $unit = 'g'): string => "<foodvalue><eur_name>{$code}</eur_name><best_location>{$amount}</best_location><v_unit>{$unit}</v_unit></foodvalue>";
            $values = str_contains($body, '<cond3>923</cond3>')
                ? $value('ENERC', '840.078', 'kJ').$value('PROT', '2.51').$value('FAT', '19.9894').$value('FASAT', '12.7').$value('CHO', '3.4').$value('SUGAR', '3.4').$value('NA', '45', 'mg')
                : $value('ENERC', '1500.6', 'kJ').$value('PROT', '8.9').$value('FAT', '2.5').$value('CHO', '18.7').$value('FIBT', '13.6');

            return new MockResponse('<?xml version="1.0" encoding="utf-8"?><foodresponse><food>'.$values.'</food></foodresponse>');
        });

        $bedrock = self::getContainer()->get('bedrock.http_client');
        self::assertInstanceOf(MockHttpClient::class, $bedrock);
        $bedrock->setResponseFactory(function (): JsonMockResponse {
            ++$this->matchCalls;

            return new JsonMockResponse([
                'output' => ['message' => ['content' => [['toolUse' => ['toolUseId' => 't', 'name' => 'save_matches', 'input' => ['matches' => [
                    ['key' => 'cooking_cream', 'bedca' => 'nata, líquida, para cocinar, 18% de grasa'],
                    ['key' => 'chickpeas_cooked', 'bedca' => 'Garbanzo, hervido'],
                    ['key' => 'gochujang', 'bedca' => 'Pasta de pimiento coreana'],
                ]]]]]]],
                'stopReason' => 'tool_use',
                'usage' => ['inputTokens' => 100, 'outputTokens' => 50],
            ]);
        });
    }
}
