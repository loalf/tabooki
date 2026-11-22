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
     * @param bool|null $thinking null: as configured (EXTRACTION_THINKING)
     *
     * @throws LlmException
     * @throws ExtractionException
     */
    public function fromText(Evidence $evidence, string $interfaceLanguage, ?bool $thinking = null): ExtractionResult
    {
        return $this->extract($this->textModel, [Content::text($evidence->toPrompt($interfaceLanguage))], $interfaceLanguage, thinking: $thinking);
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

    /**
     * Reads stills of the video with what is said in it, for models that take images but not
     * video (Claude). Each still comes after its time, and the transcript is timed the same way,
     * so the model can match "add this much" with what it sees.
     *
     * @param Evidence         $evidence  caption and the like; its transcript, if any, is left out
     *                                    when $transcript is given
     * @param list<VideoFrame> $frames
     * @param bool             $estimates let the model estimate amounts that aren't shown or said
     * @param string|null      $model     null: the text model
     *
     * @throws LlmException
     * @throws ExtractionException
     */
    public function fromFrames(Evidence $evidence, ?Transcript $transcript, array $frames, string $interfaceLanguage, bool $estimates = false, ?string $model = null): ExtractionResult
    {
        $content = [Content::text((null !== $transcript ? $evidence->withTranscript(null) : $evidence)->toPrompt($interfaceLanguage))];
        if (null !== $transcript && !$transcript->isEmpty()) {
            $content[] = Content::text("## What is said in the video (speech recognition, may have errors)\n".$transcript->timed());
        } elseif (null !== $transcript) {
            $content[] = Content::text('## What is said in the video'."\n".'Nothing (music or silence only).');
        }
        $content[] = Content::text(\sprintf("## Stills from the video\n%d stills in order, each after its time in the video.", \count($frames)));
        foreach ($frames as $frame) {
            $content[] = Content::text('['.Transcript::clock($frame->seconds).']');
            $content[] = Content::image($frame->jpeg, 'jpeg');
        }
        $content[] = Content::text($estimates
            ? 'Read the ingredients and amounts written on screen, listen to what is said, and watch what is added and how much. Estimate the amounts nobody gives, and flag them as estimated.'
            : 'Read the ingredients and amounts written on screen, listen to what is said, and follow what is cooked. Only state amounts that are shown, written or said.');

        return $this->extract($model ?? $this->textModel, $content, $interfaceLanguage, estimates: $estimates);
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
    private function extract(string $model, array $content, string $interfaceLanguage, int $maxTokens = 16000, ?bool $thinking = null, bool $estimates = false): ExtractionResult
    {
        $completion = $this->llm->complete(new Prompt(
            model: $model,
            system: ExtractionSchema::instructions($interfaceLanguage, $estimates),
            content: $content,
            toolName: ExtractionSchema::TOOL_NAME,
            toolDescription: 'Save the recipes found in the material, or report that there is none.',
            toolSchema: ExtractionSchema::schema($estimates),
            maxTokens: $maxTokens,
            thinking: $thinking ?? $this->thinking,
        ));

        return ExtractionResult::fromCompletion($completion);
    }
}
