<?php

declare(strict_types=1);

namespace App\Llm\Bedrock;

use App\Llm\Completion;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Amazon Bedrock's Converse API over plain HTTPS, authenticated with a Bedrock API key
 * (bearer token, AWS_BEARER_TOKEN_BEDROCK): no AWS SDK or request signing needed. Converse
 * speaks one format for every model Bedrock hosts (Claude, Amazon Nova, ...).
 *
 * The answer is requested as a tool call with toolChoice "auto" plus an instruction: forced
 * tool choice isn't accepted by every model (nor together with extended thinking).
 */
final readonly class BedrockConverseClient implements LlmClient
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

    public function complete(Prompt $prompt): Completion
    {
        if ('' === trim($this->apiKey)) {
            throw new LlmException('No Bedrock API key configured: set AWS_BEARER_TOKEN_BEDROCK in backend/.env.local.');
        }

        try {
            $response = $this->httpClient->request('POST', \sprintf(
                'https://bedrock-runtime.%s.amazonaws.com/model/%s/converse',
                $this->region,
                rawurlencode($prompt->model),
            ), [
                'auth_bearer' => trim($this->apiKey),
                'json' => $this->body($prompt),
                'timeout' => 120,
                'max_duration' => 300,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new LlmException('Bedrock request failed: '.$e->getMessage(), retryable: true, previous: $e);
        }

        if ($status >= 400) {
            $message = \is_string($data['message'] ?? null) ? $data['message'] : 'HTTP '.$status;
            throw new LlmException(\sprintf('Bedrock error (%d): %s', $status, $message), retryable: 429 === $status || $status >= 500);
        }

        return $this->completion($prompt, $data);
    }

    /** @return array<string, mixed> */
    private function body(Prompt $prompt): array
    {
        $body = [
            'system' => [['text' => $prompt->system]],
            'messages' => [[
                'role' => 'user',
                'content' => array_map($this->contentBlock(...), $prompt->content),
            ]],
            'inferenceConfig' => ['maxTokens' => $prompt->maxTokens],
            'toolConfig' => [
                'tools' => [['toolSpec' => [
                    'name' => $prompt->toolName,
                    'description' => $prompt->toolDescription,
                    'inputSchema' => ['json' => $prompt->toolSchema],
                    // Strict tool use: Claude's answer always matches the schema (Nova doesn't take it).
                ] + (self::isClaude($prompt->model) ? ['strict' => true] : [])]],
                'toolChoice' => ['auto' => new \stdClass()],
            ],
        ];

        // Recent Claude models think by default; Converse passes model-specific settings through
        // additionalModelRequestFields. Nova has no such switch.
        if (!$prompt->thinking && self::isClaude($prompt->model)) {
            $body['additionalModelRequestFields'] = ['thinking' => ['type' => 'disabled']];
        }

        return $body;
    }

    /** Model ids and inference profiles look like "anthropic.claude-…" or "eu.anthropic.claude-…". */
    private static function isClaude(string $model): bool
    {
        return 1 === preg_match('/(^|\.)anthropic\.claude/', $model);
    }

    /** @return array<string, mixed> */
    private function contentBlock(Content $content): array
    {
        return match ($content->type) {
            'image' => ['image' => ['format' => $content->format, 'source' => ['bytes' => base64_encode($content->data)]]],
            'video' => ['video' => ['format' => $content->format, 'source' => ['bytes' => base64_encode($content->data)]]],
            default => ['text' => $content->data],
        };
    }

    /** @param array<mixed> $data */
    private function completion(Prompt $prompt, array $data): Completion
    {
        $output = \is_array($data['output'] ?? null) ? $data['output'] : [];
        $message = \is_array($output['message'] ?? null) ? $output['message'] : [];
        $blocks = $message['content'] ?? [];
        $toolInput = null;
        $text = '';
        foreach (\is_array($blocks) ? $blocks : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (\is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
            $toolUse = $block['toolUse'] ?? null;
            if (\is_array($toolUse) && ($toolUse['name'] ?? null) === $prompt->toolName && \is_array($toolUse['input'] ?? null)) {
                /** @var array<string, mixed> $input */
                $input = $toolUse['input'];
                $toolInput = $input;
            }
        }

        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new Completion(
            toolInput: $toolInput,
            text: $text,
            model: $prompt->model,
            inputTokens: \is_int($usage['inputTokens'] ?? null) ? $usage['inputTokens'] : 0,
            outputTokens: \is_int($usage['outputTokens'] ?? null) ? $usage['outputTokens'] : 0,
            stopReason: \is_string($data['stopReason'] ?? null) ? $data['stopReason'] : 'unknown',
        );
    }
}
