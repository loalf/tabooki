<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Household;
use App\Entity\Ingredient;
use App\Enum\Aisle;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use App\Message\ClassifyIngredients;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Asks the text model to name the household's new ingredients in Spanish and English, put each
 * in an aisle and say whether it's a pantry staple. One call for up to 150 of them. On failure
 * they stay as they are (recipe names, "other" aisle) and are tried again with the next new ones.
 */
#[AsMessageHandler]
final readonly class ClassifyIngredientsHandler
{
    private const int BATCH = 150;
    private const string TOOL = 'save_ingredients';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LlmClient $llm,
        private LoggerInterface $logger,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    public function __invoke(ClassifyIngredients $message): void
    {
        $household = $this->entityManager->find(Household::class, Uuid::fromString($message->householdId));
        if (null === $household) {
            return;
        }
        /** @var list<Ingredient> $pending */
        $pending = $this->entityManager->getRepository(Ingredient::class)->findBy(['household' => $household, 'classifiedAt' => null], limit: self::BATCH);
        if ([] === $pending) {
            return;
        }

        $lines = array_map(
            static fn (Ingredient $i): string => \sprintf('- %s (seen as: %s)', $i->getCanonicalKey(), $i->getNameEs() ?? $i->getNameEn() ?? $i->getCanonicalKey()),
            $pending,
        );
        try {
            $completion = $this->llm->complete(new Prompt(
                model: $this->model,
                system: self::INSTRUCTIONS,
                content: [Content::text("Classify these ingredients:\n".implode("\n", $lines))],
                toolName: self::TOOL,
                toolDescription: 'Save the name, aisle and staple flag of each ingredient.',
                toolSchema: self::schema(),
                maxTokens: 12000,
                thinking: false,
            ));
        } catch (LlmException $e) {
            $this->logger->warning('Ingredient classification failed: {error}', ['error' => $e->getMessage()]);

            return;
        }

        $byKey = [];
        foreach ($pending as $ingredient) {
            $byKey[$ingredient->getCanonicalKey()] = $ingredient;
        }
        $answers = $completion->toolInput['ingredients'] ?? [];
        $answers = \is_array($answers) ? $answers : [];
        foreach ($answers as $answer) {
            if (!\is_array($answer) || !\is_string($answer['key'] ?? null) || !isset($byKey[$answer['key']])) {
                continue;
            }
            $byKey[$answer['key']]->classify(
                \is_string($answer['name_es'] ?? null) ? $answer['name_es'] : null,
                \is_string($answer['name_en'] ?? null) ? $answer['name_en'] : null,
                Aisle::tryFrom(\is_string($answer['aisle'] ?? null) ? $answer['aisle'] : '') ?? Aisle::Other,
                true === ($answer['staple'] ?? false),
            );
        }
        $this->entityManager->flush();
        $this->logger->info('Classified {count} ingredients ({in} in / {out} out tokens)', [
            'count' => \count($answers), 'in' => $completion->inputTokens, 'out' => $completion->outputTokens,
        ]);
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You keep the grocery catalog of a household in Spain that cooks from Spanish and English recipes. For each ingredient (a stable English key, and the name a recipe used), give:
        - name_es and name_en: the everyday shopping name, singular, lowercase, no brand or preparation ("cebolla" / "onion", "garbanzos cocidos" / "cooked chickpeas").
        - aisle: produce (fruit, vegetables, fresh herbs), meat_fish, dairy_eggs (milk, yogurt, cheese, butter, eggs, cream), bakery (bread), pantry (rice, pasta, pulses, tins, oils, flour, sugar, sauces, stock, nuts), spices (dried herbs and spices, salt, pepper), frozen, drinks (including wine and beer used for cooking), other.
        - staple: true only for what nearly every kitchen always has and nobody writes on a list: salt, pepper, cooking oil, vinegar, sugar, flour, water, common dried spices and herbs. Not true for fresh produce, dairy, meat or anything bought for a recipe.
        Answer by calling the save_ingredients tool once, with every ingredient.
        TXT;

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ingredients'],
            'properties' => [
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['key', 'name_es', 'name_en', 'aisle', 'staple'],
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name_es' => ['type' => 'string'],
                            'name_en' => ['type' => 'string'],
                            'aisle' => ['type' => 'string', 'enum' => array_map(static fn (Aisle $a): string => $a->value, Aisle::cases())],
                            'staple' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
