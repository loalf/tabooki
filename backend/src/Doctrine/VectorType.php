<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** pgvector's `vector(n)` as a list of floats. Its text form is a JSON array: "[0.1,-0.2,…]". */
final class VectorType extends Type
{
    public const string NAME = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $length = $column['length'] ?? null;

        return \sprintf('vector(%d)', \is_int($length) ? $length : 512);
    }

    /** @param list<float>|null $value */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : self::literal($value);
    }

    /** @return list<float>|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if (null === $value) {
            return null;
        }
        $floats = json_decode(\is_string($value) ? $value : '[]', true);

        return \is_array($floats) ? array_values(array_map(floatval(...), array_filter($floats, is_numeric(...)))) : [];
    }

    /** @param list<float> $vector */
    public static function literal(array $vector): string
    {
        return json_encode(array_map(floatval(...), $vector), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
