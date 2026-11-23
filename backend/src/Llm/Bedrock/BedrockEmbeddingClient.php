<?php

declare(strict_types=1);

namespace App\Llm\Bedrock;

use App\Llm\Embedding;
use App\Llm\EmbeddingClient;
use App\Llm\LlmException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Embeddings through Bedrock's InvokeModel, with the same API key as BedrockConverseClient. Each
 * family has its own body: Cohere Embed (v4, multilingual; told whether it's a query or a
 * document, which helps search) and Amazon Titan Text Embeddings V2.
 */
final readonly class BedrockEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        #[Autowire(service: 'bedrock.http_client')]
        private HttpClientInterface $httpClient,
        #[Autowire('%env(AWS_BEARER_TOKEN_BEDROCK)%')]
        private string $apiKey,
        #[Autowire('%env(AWS_REGION)%')]
        private string $region,
    ) {
    }

    public function embed(string $model, string $text, string $purpose): Embedding
    {
        if ('' === trim($this->apiKey)) {
            throw new LlmException('No Bedrock API key configured: set AWS_BEARER_TOKEN_BEDROCK in backend/.env.local.');
        }
        $cohere = str_contains($model, 'cohere.');
        $text = mb_substr($text, 0, 20000);
        $body = $cohere
            ? ['texts' => [$text], 'input_type' => self::QUERY === $purpose ? 'search_query' : 'search_document', 'embedding_types' => ['float'], 'output_dimension' => Embedding::DIMENSIONS]
            : ['inputText' => $text, 'dimensions' => Embedding::DIMENSIONS, 'normalize' => true];
        try {
            $response = $this->httpClient->request('POST', \sprintf('https://bedrock-runtime.%s.amazonaws.com/model/%s/invoke', $this->region, rawurlencode($model)), [
                'auth_bearer' => trim($this->apiKey),
                'json' => $body,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            $tokens = (int) ($response->getHeaders(false)['x-amzn-bedrock-input-token-count'][0] ?? 0);
        } catch (ExceptionInterface $e) {
            throw new LlmException('Bedrock request failed: '.$e->getMessage(), retryable: true, previous: $e);
        }
        if ($status >= 400) {
            $message = \is_string($data['message'] ?? null) ? $data['message'] : 'HTTP '.$status;
            throw new LlmException(\sprintf('Bedrock error (%d): %s', $status, $message), retryable: 429 === $status || $status >= 500);
        }
        $embeddings = $data['embeddings'] ?? null;
        $floats = \is_array($embeddings) ? $embeddings['float'] ?? null : null;
        $vector = $cohere ? (\is_array($floats) ? $floats[0] ?? null : null) : ($data['embedding'] ?? null);
        if (!\is_array($vector) || Embedding::DIMENSIONS !== \count($vector)) {
            throw new LlmException('Bedrock returned no embedding of '.Embedding::DIMENSIONS.' dimensions.');
        }

        return new Embedding(array_values(array_map(static fn (mixed $x): float => is_numeric($x) ? (float) $x : 0.0, $vector)), $model, $tokens);
    }
}
