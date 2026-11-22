<?php

declare(strict_types=1);

namespace App\Planning;

use App\Entity\Recipe;
use App\Enum\FoodGroup;
use App\Enum\Heaviness;
use App\Llm\Completion;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the text model how filling and how spicy a recipe is and which food groups it contains,
 * for the household's likings (docs/PLAN.md §6.2). One short call per recipe, no thinking.
 */
final readonly class RecipeAnnotator
{
    public const string TOOL = 'save_recipe_facts';

    public function __construct(
        private LlmClient $llm,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @return array{RecipeFacts, Completion}
     *
     * @throws LlmException        the call failed
     * @throws AnnotationException the answer isn't the expected shape
     */
    public function annotate(Recipe $recipe): array
    {
        $ingredients = array_map(
            static fn ($i): string => $i->getRawText().($i->isOptional() ? ' (optional)' : ''),
            $recipe->getIngredients(),
        );
        $text = "# {$recipe->getTitle()}\n\n## Ingredients\n".implode("\n", array_map(static fn (string $line): string => '- '.$line, $ingredients))
            ."\n\n## Steps\n".implode("\n", array_map(static fn (string $step, int $n): string => ($n + 1).'. '.$step, $recipe->getSteps(), array_keys($recipe->getSteps())));

        $completion = $this->llm->complete(new Prompt(
            model: $this->model,
            system: self::INSTRUCTIONS,
            content: [Content::text(mb_substr($text, 0, 20000))],
            toolName: self::TOOL,
            toolDescription: 'Save how filling and how spicy the recipe is, and the food groups it contains.',
            toolSchema: self::schema(),
            maxTokens: 1000,
            thinking: false,
        ));

        $facts = RecipeFacts::fromToolInput($completion->toolInput ?? []);
        if (null === $facts) {
            throw new AnnotationException('The model didn\'t answer with the recipe\'s facts.');
        }

        return [$facts, $completion];
    }

    private const string INSTRUCTIONS = <<<'TXT'
        You help a household in Spain plan its weekly meals from its own recipes. Read the recipe (it may be in Spanish or English) and say:
        - heaviness, for one serving as a main course: "light" (salads, vegetable soups and creams, steamed or grilled fish or vegetables, poke, simple omelettes), "hearty" (stews and legumes with meat or sausage, cocido, baked pasta, lasagna, fried food, creamy or cheesy dishes, big rice dishes) or "medium" (everything in between).
        - spice: 0 not spicy, 1 mild (a little chili, a pinch of cayenne, hot paprika), 2 clearly hot.
        - food_groups: the groups of the ingredients the dish needs. red_meat is beef, veal, lamb, goat and game; pork is only pork, never red_meat too. Look inside composite ingredients: pesto has cheese and tree nuts, bread, pasta, couscous and most flours have gluten, soy sauce has soy and gluten, mayonnaise has egg, chorizo, bacon, ham and sausages are processed_meat and pork. Meat or poultry stock, gelatin and lard are animal_stock; fish stock and fish sauce are fish. Butter, cream, milk and yogurt are dairy; cheese is cheese. Wine, beer or spirits used for cooking are alcohol. Leave out anything only listed as optional, and leave out groups you'd have to guess.
        - optional_food_groups: groups only the optional ingredients bring (a garnish of parmesan, "bacon, optional"); empty when there are none.
        Answer by calling the save_recipe_facts tool once.
        TXT;

    /** @return array<string, mixed> JSON Schema for strict tool use (every property required, no extra ones) */
    public static function schema(): array
    {
        $groups = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => FoodGroup::values()]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['heaviness', 'spice', 'food_groups', 'optional_food_groups'],
            'properties' => [
                'heaviness' => ['type' => 'string', 'enum' => array_map(static fn (Heaviness $h): string => $h->value, Heaviness::cases())],
                'spice' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                'food_groups' => $groups,
                'optional_food_groups' => $groups,
            ],
        ];
    }
}
