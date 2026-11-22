<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * The one shape every extraction answers in, whatever the source (docs/PLAN.md §4.2), as the
 * JSON Schema of the `save_recipes` tool, plus the instructions that go with it.
 */
final class ExtractionSchema
{
    public const string TOOL_NAME = 'save_recipes';

    /** Units we store (metric, plus kitchen units that don't convert well). null = a count ("2 eggs"). */
    public const array UNITS = ['g', 'kg', 'ml', 'l', 'tsp', 'tbsp', 'pinch', 'clove', 'can', 'slice', 'bunch', 'sprig', 'piece', 'pack'];

    public const array STATUSES = ['complete', 'partial', 'no_recipe', 'elsewhere'];

    /**
     * Written for strict tool use (BedrockConverseClient sends Claude "strict": the answer always
     * matches it): every object closed with additionalProperties false, no numeric bounds, and
     * nullable enums as anyOf. Without strict, Claude now and then sent "recipes" as a JSON string
     * with a broken ending, which the app couldn't read.
     *
     * @param bool $estimates let the model estimate amounts and servings it can't read, flagging
     *                        them (for videos: what is cooked shows roughly how much goes in)
     *
     * @return array<string, mixed>
     */
    public static function schema(bool $estimates = false): array
    {
        $nullable = static fn (string $type, string $description): array => ['type' => [$type, 'null'], 'description' => $description];

        $ingredient = [
            'raw' => ['type' => 'string', 'description' => 'The ingredient line as the cook will read it, in the cook\'s language, with the amount stored in quantity and unit ("450 g de salchicha"); see the rules.'],
            'group' => $nullable('string', 'Sub-heading the ingredient belongs to ("Para la salsa"), if the recipe groups them.'),
            'quantity' => $nullable('number', $estimates
                ? 'Amount in the unit below, converted to metric: as shown, written or said, or else your estimate (then set estimated). null for "to taste" amounts.'
                : 'Amount in the unit below, converted to metric. null when the material gives no amount ("salt to taste").'),
            // anyOf rather than a nullable type with an enum: strict tool use only takes this form.
            'unit' => ['anyOf' => [['type' => 'string', 'enum' => self::UNITS], ['type' => 'null']], 'description' => 'Metric or kitchen unit; null for counted items ("2 eggs") or no amount.'],
            'name' => ['type' => 'string', 'description' => 'Ingredient name in the cook\'s language, singular, without amount or preparation ("cebolla").'],
            'canonical_key' => ['type' => 'string', 'description' => 'Stable lowercase English snake_case key for the ingredient, the same whatever the recipe language ("onion", "chickpeas_canned", "olive_oil"), so shopping lists can merge "cebolla" and "onion".'],
            'prep' => $nullable('string', 'Preparation, in the cook\'s language ("picada", "en dados").'),
            'optional' => ['type' => 'boolean'],
        ];
        if ($estimates) {
            $ingredient['estimated'] = ['type' => 'boolean', 'description' => 'true when the quantity is your estimate, not shown, written or said in the material.'];
        }
        $recipe = [
            'title' => ['type' => 'string', 'description' => 'The dish name, short, in the cook\'s language (not the post\'s clickbait headline).'],
            'language' => ['type' => 'string', 'enum' => ['es', 'en'], 'description' => 'The language you wrote the recipe in: the cook\'s.'],
            'servings' => $nullable('integer', $estimates ? 'Servings stated by the material, or else your estimate from the amounts (then set servings_estimated).' : 'Servings stated by the material; null if not stated.'),
        ];
        if ($estimates) {
            $recipe['servings_estimated'] = ['type' => 'boolean', 'description' => 'true when servings is your estimate.'];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'confidence', 'recipes'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => self::STATUSES,
                    'description' => 'complete: the recipe (ingredients with quantities, and steps) is in the material. partial: there is a recipe but quantities or steps are missing. no_recipe: there is no recipe in the material. elsewhere: the material says the recipe is somewhere you can\'t see (in the comments, sent by DM, behind a link, in the video only).',
                ],
                'message' => $nullable('string', 'When status is not "complete": one short sentence for the cook explaining what is missing or where the recipe is, written in the requested interface language.'),
                'confidence' => ['type' => 'number', 'description' => 'From 0 to 1: how sure you are that the recipes below are faithful to the material.'],
                'missing_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What the material doesn\'t say, e.g. "quantities", "servings", "steps", "times".'],
                'recipes' => [
                    'type' => 'array',
                    'description' => 'Every distinct recipe in the material (usually one; a "3 dinners" post has three). Empty when there is none.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'language', 'ingredients', 'steps'],
                        'properties' => [
                            ...$recipe,
                            'prep_minutes' => $nullable('integer', 'Preparation time in minutes if stated.'),
                            'cook_minutes' => $nullable('integer', 'Cooking time in minutes if stated.'),
                            'meal_types' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => ['lunch', 'dinner']],
                                'description' => 'Whether it suits lunch (main midday meal, Spanish style: heartier dishes, stews, rice, pasta) and/or dinner (lighter: soups, salads, eggs, fish). Most dishes suit both.',
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Up to 6 short lowercase tags in the cook\'s language: main ingredient or type (pasta, legumbres, pescado), diet (vegetariana, vegana, sin gluten) only when certain, and "rápida"/"quick" if under 30 minutes.',
                            ],
                            'ingredients' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['raw', 'name', 'canonical_key'],
                                    'properties' => $ingredient,
                                ],
                            ],
                            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The method, one step per item, in the cook\'s language, temperatures in °C.'],
                            'notes' => $nullable('string', 'Useful tips or variations from the material, if any.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function instructions(string $interfaceLanguage, bool $estimates = false): string
    {
        $language = 'en' === $interfaceLanguage ? 'English' : 'Spanish';
        $code = 'en' === $interfaceLanguage ? 'en' : 'es';
        $faithfulness = $estimates
            ? 'Use what the material says, writes or shows first. When an amount isn\'t given, estimate it from what is shown (how big the spoonful or the pour is, how full the pan or bowl gets, package and can sizes, how many pieces) and from usual proportions for the dish and its servings, and set estimated: true on that ingredient. Estimate servings the same way when they aren\'t stated. Don\'t estimate "to taste" amounts (salt, pepper): leave them null. Steps describe what is done, even when nobody says it. Never invent ingredients or steps that aren\'t in the material. A recipe with estimated amounts can be "complete"; list "quantities (estimated)" in missing_fields.'
            : 'Never invent. Use only what the material says or shows. If quantities, servings or steps aren\'t there, leave them null or empty, say so in missing_fields, and use status "partial". Don\'t guess amounts from experience.';

        return <<<TXT
            You turn cooking content (a recipe blog page, a YouTube video's description and transcript, an Instagram or TikTok caption, the pictures of a photo or carousel post, or a video) into structured recipes for a household meal planner.

            Always answer by calling the `save_recipes` tool exactly once. Do not answer in plain text.

            Rules:
            - {$faithfulness}
            - The cook reads {$language}. Write every recipe in {$language}: title, ingredient lines, names, preparations and groups, steps, notes and tags. When the material is in another language, translate it the way a cook writing in {$language} would (natural ingredient names, not word for word), and set language to "{$code}".
            - Units: store metric. Convert ounces, pounds, cups, fluid ounces, sticks of butter and °F to g, ml and °C (cups of flour ≈ 120 g, sugar ≈ 200 g, rice ≈ 185 g; other cups become ml; 1 stick of butter = 113 g). Keep teaspoons (tsp), tablespoons (tbsp), pinches, cloves, cans and counted items as they are. Spanish "cucharada" = tbsp, "cucharadita" = tsp, "pizca" = pinch, "diente" = clove. Write each raw line in {$language} with the amount you stored, so it reads "450 g de salchicha" and not "1 lb sausage"; keep the material's own wording when it's already in {$language} and in those units.
            - Ingredients that aren't measured ("sal", "aceite para freír", "salt and pepper to taste") have quantity null: that's normal and doesn't make a recipe partial.
            - Ignore everything that isn't the recipe: life stories, ads, hashtags, calls to follow or comment, sponsor codes.
            - If the post only says the recipe is in the comments, a link, a DM or the video, use status "elsewhere" with no recipes.
            - Write "message" (when needed) in {$language}, addressed to the cook, in one short sentence.
            TXT;
    }
}
