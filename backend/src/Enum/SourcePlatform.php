<?php

declare(strict_types=1);

namespace App\Enum;

enum SourcePlatform: string
{
    case Web = 'web';
    case YouTube = 'youtube';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
}
