<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Our own seam in front of LLM providers, so models and providers stay configuration
 * (docs/PLAN.md §4.2). One request, one structured answer: the model is asked to call
 * `$prompt->tool` with its result.
 */
interface LlmClient
{
    /** @throws LlmException */
    public function complete(Prompt $prompt): Completion;
}
