<?php

declare(strict_types=1);

namespace App\Message;

/** Write the summary of the household's likings again, after they changed (App\Taste\TasteCompiler::summarize()). */
final readonly class SummarizeTaste
{
    public function __construct(public string $householdId)
    {
    }
}
