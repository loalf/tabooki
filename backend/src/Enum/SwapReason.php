<?php

declare(strict_types=1);

namespace App\Enum;

/** Why a planned meal was swapped for another recipe (App\Entity\SwapFeedback). */
enum SwapReason: string
{
    /** Not today: nothing to learn. */
    case NotToday = 'notToday';
    /** Something in it, or the whole dish. */
    case Dislike = 'dislike';
    case TooHeavy = 'tooHeavy';
    case TooLight = 'tooLight';
    case TooLong = 'tooLong';
}
