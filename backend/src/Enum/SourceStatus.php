<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a saved link is in the extraction pipeline (docs/PLAN.md §4).
 * Until extraction lands (M2), every link stays "pending".
 */
enum SourceStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case NeedsReview = 'needs_review';
    case NeedsInput = 'needs_input';
    case Failed = 'failed';
}
