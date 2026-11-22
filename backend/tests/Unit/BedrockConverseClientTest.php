<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Llm\Bedrock\BedrockConverseClient;
use App\Llm\Content;
use App\Llm\LlmException;
use App\Llm\Prompt;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class BedrockConverseClientTest extends TestCase
{
    public function testSendsAConverseRequestAndReadsTheToolCall(): void
    {
        $response = new JsonMockResponse([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Saving.'],
                ['toolUse' => ['toolUseId' => 't1', 'name' => 'save', 'input' => ['status' => 'complete']]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 120, 'outputTokens' => 30],
        ]);
        $client = new BedrockConverseClient(new MockHttpClient($response), ' ABSK-secret ', 'eu-west-1');

        $completion = $client->complete($this->prompt([Content::text('hello'), Content::video('VIDEO')]));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://bedrock-runtime.eu-west-1.amazonaws.com/model/eu.amazon.nova-pro-v1%3A0/converse', $response->getRequestUrl());
        /** @var array{headers: list<string>, body: string} $options */
        $options = $response->getRequestOptions();
        self::assertContains('Authorization: Bearer ABSK-secret', $options['headers']);
        /** @var array{system: mixed, messages: list<array{content: list<mixed>}>, toolConfig: array{tools: list<array{toolSpec: array{name: string}}>, toolChoice: mixed}} $body */
        $body = json_decode($options['body'], true);
        self::assertSame([['text' => 'system prompt']], $body['system']);
        self::assertSame(['text' => 'hello'], $body['messages'][0]['content'][0]);
        self::assertSame(['video' => ['format' => 'mp4', 'source' => ['bytes' => base64_encode('VIDEO')]]], $body['messages'][0]['content'][1]);
        self::assertSame('save', $body['toolConfig']['tools'][0]['toolSpec']['name']);
        self::assertSame(['auto' => []], $body['toolConfig']['toolChoice']);

        self::assertSame(['status' => 'complete'], $completion->toolInput);
        self::assertSame('Saving.', $completion->text);
        self::assertSame(120, $completion->inputTokens);
        self::assertSame(30, $completion->outputTokens);
        self::assertSame('tool_use', $completion->stopReason);
    }

    public function testThinkingCanBeTurnedOffForClaudeModels(): void
    {
        $send = static function (string $model, bool $thinking): array {
            $response = new JsonMockResponse(['output' => ['message' => ['content' => []]], 'usage' => []]);
            $client = new BedrockConverseClient(new MockHttpClient($response), 'key', 'eu-west-1');
            $client->complete(new Prompt($model, 'system prompt', [Content::text('x')], 'save', 'Save it.', ['type' => 'object'], thinking: $thinking));
            /** @var array{body: string} $options */
            $options = $response->getRequestOptions();
            /** @var array<string, mixed> $body */
            $body = json_decode($options['body'], true);

            return $body;
        };

        self::assertSame(['thinking' => ['type' => 'disabled']], $send('eu.anthropic.claude-haiku-5-5', false)['additionalModelRequestFields'] ?? null);
        self::assertArrayNotHasKey('additionalModelRequestFields', $send('eu.anthropic.claude-haiku-5-5', true));
        self::assertArrayNotHasKey('additionalModelRequestFields', $send('eu.amazon.nova-pro-v1:0', false));

        $toolSpec = static function (array $body): mixed {
            $config = $body['toolConfig'] ?? null;

            return \is_array($config) && \is_array($config['tools'] ?? null) && \is_array($config['tools'][0] ?? null) ? ($config['tools'][0]['toolSpec'] ?? null) : null;
        };
        $claude = $toolSpec($send('eu.anthropic.claude-haiku-5-5', true));
        self::assertIsArray($claude);
        self::assertTrue($claude['strict'] ?? null, 'strict tool use for Claude');
        $nova = $toolSpec($send('eu.amazon.nova-pro-v1:0', true));
        self::assertIsArray($nova);
        self::assertArrayNotHasKey('strict', $nova);
    }

    public function testThrottlingIsRetryable(): void
    {
        $client = new BedrockConverseClient(new MockHttpClient(new JsonMockResponse(['message' => 'Too many requests'], ['http_code' => 429])), 'key', 'eu-west-1');

        try {
            $client->complete($this->prompt([Content::text('x')]));
            self::fail('Expected an exception');
        } catch (LlmException $e) {
            self::assertTrue($e->retryable);
            self::assertStringContainsString('Too many requests', $e->getMessage());
        }
    }

    public function testValidationErrorsAreNotRetryable(): void
    {
        $client = new BedrockConverseClient(new MockHttpClient(new JsonMockResponse(['message' => 'The provided model identifier is invalid.'], ['http_code' => 400])), 'key', 'eu-west-1');

        $this->expectExceptionObject(new LlmException('Bedrock error (400): The provided model identifier is invalid.'));
        $client->complete($this->prompt([Content::text('x')]));
    }

    public function testAMissingKeyIsReportedClearly(): void
    {
        $client = new BedrockConverseClient(new MockHttpClient(), '', 'eu-west-1');

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/AWS_BEARER_TOKEN_BEDROCK/');
        $client->complete($this->prompt([Content::text('x')]));
    }

    /** @param list<Content> $content */
    private function prompt(array $content): Prompt
    {
        return new Prompt('eu.amazon.nova-pro-v1:0', 'system prompt', $content, 'save', 'Save it', ['type' => 'object']);
    }
}
