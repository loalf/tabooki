<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Food\FoodCatalog;
use App\Llm\Content;
use App\Llm\LlmClient;
use App\Llm\LlmException;
use App\Llm\Prompt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns gathered material into recipes with one LLM call. The model is configuration
 * (BEDROCK_TEXT_MODEL), not code; videos reach it as stills and a transcript (fromFrames).
 */
final readonly class RecipeExtractor
{
    public function __construct(
        private LlmClient $llm,
        private FoodCatalog $foods,
        #[Autowire('%env(BEDROCK_TEXT_MODEL)%')]
        private string $textModel,
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

    /**
     * Reads the pictures of a photo or carousel post with its caption: recipe cards, ingredient
     * lists and steps are often written on the slides rather than in the caption.
     *
     * @param list<PostImage> $images in the order the post shows them
     *
     * @throws LlmException
     * @throws ExtractionException
     */
    public function fromImages(Evidence $evidence, array $images, string $interfaceLanguage): ExtractionResult
    {
        $content = [
            Content::text($evidence->toPrompt($interfaceLanguage)),
            Content::text(\sprintf("## Pictures of the post\n%d %s in the order the post shows them. A recipe is often written across several of them.", \count($images), 1 === \count($images) ? 'picture' : 'pictures')),
        ];
        foreach ($images as $i => $image) {
            $content[] = Content::text('['.($i + 1).']');
            $content[] = Content::image($image->bytes, $image->format);
        }
        $content[] = Content::text('Read the ingredients, amounts and steps written on the pictures together with the caption. Only state amounts that are written or clearly shown.');

        return $this->extract($this->textModel, $content, $interfaceLanguage);
    }

    public function textModel(): string
    {
        return $this->textModel;
    }

    /** @param list<Content> $content */
    private function extract(string $model, array $content, string $interfaceLanguage, int $maxTokens = 16000, ?bool $thinking = null, bool $estimates = false): ExtractionResult
    {
        $completion = $this->llm->complete(new Prompt(
            model: $model,
            system: ExtractionSchema::instructions($interfaceLanguage, $estimates, $this->foods->knownKeys()),
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
