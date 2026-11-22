<?php

declare(strict_types=1);

namespace App\Taste;

/** What the model made of the questionnaire, before the household confirms it. */
final readonly class TasteDraft
{
    /**
     * @param array{es: string, en: string}|null                   $summary
     * @param list<array{question: string, options: list<string>}> $questions follow-ups, in the reader's language
     */
    public function __construct(
        public TasteRules $rules,
        public ?string $notes,
        public ?array $summary,
        public array $questions = [],
    ) {
    }
}
