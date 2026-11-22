<?php

declare(strict_types=1);

namespace App\Taste;

/** A short name in both languages ("cilantro" / "coriander"), for rules about things that aren't food groups. */
final readonly class Names
{
    /** For OpenAPI. */
    public const array API_SCHEMA = [
        'type' => 'object',
        'required' => ['es', 'en'],
        'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']],
    ];

    public function __construct(public string $es, public string $en)
    {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }
        $clean = static fn (mixed $value): string => \is_string($value) ? mb_substr(trim($value), 0, 40) : '';
        $es = $clean($data['es'] ?? null);
        $en = $clean($data['en'] ?? null);
        if ('' === $es && '' === $en) {
            return null;
        }

        return new self('' === $es ? $en : $es, '' === $en ? $es : $en);
    }

    /** @return array{es: string, en: string} */
    public function toArray(): array
    {
        return ['es' => $this->es, 'en' => $this->en];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['es', 'en'],
            'properties' => ['es' => ['type' => 'string'], 'en' => ['type' => 'string']],
        ];
    }
}
