<?php

declare(strict_types=1);

namespace App\Llm;

interface EmbeddingClient
{
    /** What is embedded: something to be found, or what is searched for (some models embed them differently). */
    public const string DOCUMENT = 'document';
    public const string QUERY = 'query';

    /**
     * @param self::DOCUMENT|self::QUERY $purpose
     *
     * @return Embedding of Embedding::DIMENSIONS
     *
     * @throws LlmException
     */
    public function embed(string $model, string $text, string $purpose): Embedding;
}
