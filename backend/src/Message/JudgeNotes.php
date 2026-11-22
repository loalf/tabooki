<?php

declare(strict_types=1);

namespace App\Message;

/** Read the household's recipes against its free-text notes, those not read yet (App\Taste\NotesJudge). */
final readonly class JudgeNotes
{
    public function __construct(public string $householdId)
    {
    }
}
