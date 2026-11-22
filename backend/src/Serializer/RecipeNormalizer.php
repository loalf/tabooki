<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Recipe;
use App\Entity\User;
use App\Enum\Language;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serves each recipe in the reader's language: when it was written in the other one and has been
 * translated, the translation replaces the title, tags, ingredient lines, steps and notes, and
 * `language` and `translatedFrom` say so. Wherever a recipe appears (library, recipe page, menu).
 */
final class RecipeNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string CALLED = 'recipe_normalizer_called';

    private const array INGREDIENT_FIELDS = ['rawText', 'name', 'prep', 'groupName'];

    public function __construct(private readonly Security $security)
    {
    }

    /** @return array<mixed>|string|int|float|bool|\ArrayObject<array-key, mixed>|null */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        \assert($data instanceof Recipe);
        $normalized = $this->normalizer->normalize($data, $format, [...$context, self::CALLED => true]);

        $user = $this->security->getUser();
        $reader = Language::ofLocale($user instanceof User ? $user->getLocale() : null);
        $translation = $reader === $data->getLanguage() ? null : $data->getTranslation();
        if (null === $translation || !\is_array($normalized)) {
            return $normalized;
        }

        // Null fields are left out of responses, so a field the group serializes may be missing.
        $full = \in_array('recipe:read', (array) ($context['groups'] ?? []), true);
        foreach (['title' => $translation->title, 'tags' => $translation->tags, 'steps' => $translation->steps, 'notes' => $translation->notes] as $field => $value) {
            if (\array_key_exists($field, $normalized) || ($full && null !== $value)) {
                $normalized[$field] = $value;
            }
        }
        if (\is_array($normalized['ingredients'] ?? null) && \count($normalized['ingredients']) === \count($translation->ingredients)) {
            foreach (array_values($normalized['ingredients']) as $i => $ingredient) {
                if (!\is_array($ingredient)) {
                    continue;
                }
                foreach (self::INGREDIENT_FIELDS as $field) {
                    $value = $translation->ingredients[$i][$field];
                    if (null !== $value) {
                        $ingredient[$field] = $value;
                    } else {
                        unset($ingredient[$field]);
                    }
                }
                $normalized['ingredients'][$i] = $ingredient;
            }
        }
        $normalized['language'] = $reader->value;
        if ($full) {
            $normalized['translatedFrom'] = $data->getLanguage()->value;
        }

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Recipe && !isset($context[self::CALLED]);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Recipe::class => false];
    }
}
