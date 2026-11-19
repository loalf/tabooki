<?php

declare(strict_types=1);

namespace App\Llm;

final class LlmException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
