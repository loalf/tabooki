<?php

declare(strict_types=1);

namespace App\Taste;

/** "More legumes", "less pasta": recipes with any of these tags (in English) come up more, or less. */
final readonly class Preference
{
    /** @param non-empty-list<string> $tags */
    public function __construct(public array $tags, public Names $name, public bool $more)
    {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }
        $tags = [];
        foreach (\is_array($data['tags'] ?? null) ? $data['tags'] : [] as $tag) {
            $tag = \is_string($tag) ? mb_strtolower(trim($tag)) : '';
            if ('' !== $tag && mb_strlen($tag) <= 40 && !\in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        $name = Names::fromArray($data['name'] ?? null);
        $weight = $data['weight'] ?? null;

        return [] === $tags || null === $name || !\in_array($weight, ['more', 'less'], true) ? null : new self(\array_slice($tags, 0, 20), $name, 'more' === $weight);
    }

    /** @return array{tags: list<string>, name: array{es: string, en: string}, weight: string} */
    public function toArray(): array
    {
        return ['tags' => $this->tags, 'name' => $this->name->toArray(), 'weight' => $this->more ? 'more' : 'less'];
    }
}
