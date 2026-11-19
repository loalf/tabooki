<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns gathered material into recipes with one LLM call. Which model reads text and which
 * watches videos is configuration (BEDROCK_TEXT_MODEL, BEDROCK_VIDEO_MODEL), not code.
 */
final readonly class RecipeExtractor
{
    /** Amazon Nova v1 models cap output at 10,000 tokens; a recipe needs far less. */
    private const int VIDEO_MAX_TOKENS = 8000;

    public function __construct(
        private LlmClient $llm,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $textModel,
        #[Autowire('%env(BEDROCK_VIDEO_MODEL)%')]
        private string $videoModel,
        #[Autowire('%env(bool:EXTRACTION_THINKING)%')]
        private bool $thinking = true,
    ) {
    }

    /**
     * @throws LlmException
     * @throws ExtractionException
     */
    public function fromText(Evidence $evidence, string $interfaceLanguage): ExtractionResult
    {
        return $this->extract($this->textModel, [Content::text($evidence->toPrompt($interfaceLanguage))], $interfaceLanguage);
    }

    /**
     * Watches the video (on-screen text, what is cooked) along with the caption and any transcript.
     *
     * @throws LlmException
     * @throws ExtractionException
     */
    public function fromVideo(Evidence $evidence, string $videoBytes, string $interfaceLanguage): ExtractionResult
    {
        return $this->extract($this->videoModel, [
            Content::video($videoBytes),
            Content::text($evidence->toPrompt($interfaceLanguage)."\n\n## The video\nThe video is attached. Read the ingredients and amounts shown on screen and follow what is cooked. Only state amounts that are shown, written or said."),
        ], $interfaceLanguage, self::VIDEO_MAX_TOKENS);
    }

    public function textModel(): string
    {
        return $this->textModel;
    }

    public function videoModel(): string
    {
        return $this->videoModel;
    }

    /** @param list<Content> $content */
    private function extract(string $model, array $content, string $interfaceLanguage, int $maxTokens = 16000): ExtractionResult
    {
        $completion = $this->llm->complete(new Prompt(
            model: $model,
            system: ExtractionSchema::instructions($interfaceLanguage),
            content: $content,
            toolName: ExtractionSchema::TOOL_NAME,
            toolDescription: 'Save the recipes found in the material, or report that there is none.',
            toolSchema: ExtractionSchema::schema(),
            maxTokens: $maxTokens,
            thinking: $this->thinking,
        ));

        return ExtractionResult::fromCompletion($completion);
    }
}
