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
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $nullable = static fn (string $type, string $description): array => ['type' => [$type, 'null'], 'description' => $description];

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
                            'title' => ['type' => 'string', 'description' => 'The dish name, short, in the recipe\'s language (not the post\'s clickbait headline).'],
                            'language' => ['type' => 'string', 'enum' => ['es', 'en'], 'description' => 'Language the recipe is written or spoken in.'],
                            'servings' => $nullable('integer', 'Servings stated by the material; null if not stated.'),
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
                                'description' => 'Up to 6 short lowercase tags in the recipe\'s language: main ingredient or type (pasta, legumbres, pescado), diet (vegetariana, vegana, sin gluten) only when certain, and "rápida"/"quick" if under 30 minutes.',
                            ],
                            'ingredients' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['raw', 'name', 'canonical_key'],
                                    'properties' => [
                                        'raw' => ['type' => 'string', 'description' => 'The ingredient line exactly as the material has it, original units and language.'],
                                        'group' => $nullable('string', 'Sub-heading the ingredient belongs to ("Para la salsa"), if the recipe groups them.'),
                                        'quantity' => $nullable('number', 'Amount in the unit below, converted to metric. null when the material gives no amount ("salt to taste").'),
                                        // anyOf rather than a nullable type with an enum: strict tool use only takes this form.
                                        'unit' => ['anyOf' => [['type' => 'string', 'enum' => self::UNITS], ['type' => 'null']], 'description' => 'Metric or kitchen unit; null for counted items ("2 eggs") or no amount.'],
                                        'name' => ['type' => 'string', 'description' => 'Ingredient name in the recipe\'s language, singular, without amount or preparation ("cebolla").'],
                                        'canonical_key' => ['type' => 'string', 'description' => 'Stable lowercase English snake_case key for the ingredient, the same whatever the recipe language ("onion", "chickpeas_canned", "olive_oil"), so shopping lists can merge "cebolla" and "onion".'],
                                        'prep' => $nullable('string', 'Preparation, in the recipe\'s language ("picada", "en dados").'),
                                        'optional' => ['type' => 'boolean'],
                                    ],
                                ],
                            ],
                            'steps' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The method, one step per item, in the recipe\'s language, temperatures in °C.'],
                            'notes' => $nullable('string', 'Useful tips or variations from the material, if any.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function instructions(string $interfaceLanguage): string
    {
        $language = 'en' === $interfaceLanguage ? 'English' : 'Spanish';

        return <<<TXT
            You turn cooking content (a recipe blog page, a YouTube video's description and transcript, an Instagram or TikTok caption, or a video) into structured recipes for a household meal planner.

            Always answer by calling the `save_recipes` tool exactly once. Do not answer in plain text.

            Rules:
            - Never invent. Use only what the material says or shows. If quantities, servings or steps aren't there, leave them null or empty, say so in missing_fields, and use status "partial". Don't guess amounts from experience.
            - Keep each recipe in its own language (Spanish or English). Don't translate.
            - Units: store metric. Convert ounces, pounds, cups, fluid ounces, sticks of butter and °F to g, ml and °C (cups of flour ≈ 120 g, sugar ≈ 200 g, rice ≈ 185 g; other cups become ml; 1 stick of butter = 113 g). Keep teaspoons (tsp), tablespoons (tbsp), pinches, cloves, cans and counted items as they are. Spanish "cucharada" = tbsp, "cucharadita" = tsp, "pizca" = pinch, "diente" = clove. The raw line always keeps the original wording.
            - Ingredients that aren't measured ("sal", "aceite para freír", "salt and pepper to taste") have quantity null: that's normal and doesn't make a recipe partial.
            - Ignore everything that isn't the recipe: life stories, ads, hashtags, calls to follow or comment, sponsor codes.
            - If the post only says the recipe is in the comments, a link, a DM or the video, use status "elsewhere" with no recipes.
            - Write "message" (when needed) in {$language}, addressed to the cook, in one short sentence.
            TXT;
    }
}
