<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Llm\Completion;

/** The model answered, but not in a form the app can use. */
final class ExtractionException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?Completion $completion = null)
    {
        parent::__construct($message);
    }
}
