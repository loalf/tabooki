<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Language a recipe is written in: that of whoever added it. A background translation keeps the
 * other one (see App\Translation), so each member of the household reads recipes in their own.
 */
enum Language: string
{
    case Es = 'es';
    case En = 'en';

    public function other(): self
    {
        return self::Es === $this ? self::En : self::Es;
    }

    /** The user's locale as a language ("es" when it isn't one we write recipes in). */
    public static function ofLocale(?string $locale): self
    {
        return self::tryFrom((string) $locale) ?? self::Es;
    }
}
