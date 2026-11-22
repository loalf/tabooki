<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SourcePlatform;
use App\Extraction\Evidence;
use App\Extraction\ExtractionResult;
use App\Extraction\RecipeExtractor;
use App\Llm\LlmException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks the Bedrock setup: which models the API key can use in the region, and a tiny
 * extraction with each configured model.
 */
#[AsCommand(name: 'app:bedrock:check', description: 'Check the Bedrock API key, region and extraction models')]
final readonly class BedrockCheckCommand
{
    public function __construct(
        #[Autowire(service: 'bedrock.http_client')]
        private HttpClientInterface $httpClient,
        private RecipeExtractor $extractor,
        #[Autowire('%env(AWS_BEARER_TOKEN_BEDROCK)%')]
        private string $apiKey,
        #[Autowire('%env(AWS_REGION)%')]
        private string $region,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('' === trim($this->apiKey)) {
            $io->error('AWS_BEARER_TOKEN_BEDROCK is empty. Add it to backend/.env.local (development) or the .env next to compose.prod.yaml (production).');

            return Command::FAILURE;
        }
        $io->definitionList(
            ['Region' => $this->region],
            ['Text model' => $this->extractor->textModel()],
        );

        $available = $this->availableModels($io);
        if (null !== $available) {
            $model = $this->extractor->textModel();
            if (!\in_array($model, $available, true)) {
                $io->warning(\sprintf('Text model "%s" is not in the list your key can see in %s.', $model, $this->region));
            }
            $candidates = array_values(array_filter($available, static fn (string $id): bool => 1 === preg_match('/claude/i', $id)));
            sort($candidates);
            $io->section('Claude models available in '.$this->region);
            $io->listing($candidates ?: ['(none)']);
        }

        $io->section('Test extraction');
        $evidence = new Evidence(SourcePlatform::Web, 'https://example.com/huevos-fritos', description: "Huevos fritos para 2: 4 huevos, 100 ml de aceite de oliva, sal.\nCalienta el aceite y fríe los huevos de dos en dos.");
        $ok = true;
        foreach (['text' => fn (): ExtractionResult => $this->extractor->fromText($evidence, 'es')] as $kind => $run) {
            try {
                $result = $run();
                $io->success(\sprintf(
                    '%s model answered: status %s, %d recipe(s) ("%s"), %d input + %d output tokens.',
                    ucfirst($kind), $result->status, \count($result->recipes), $result->recipes[0]->title ?? '-',
                    $result->completion->inputTokens, $result->completion->outputTokens,
                ));
            } catch (LlmException|\App\Extraction\ExtractionException $e) {
                $io->error(ucfirst($kind).' model failed: '.$e->getMessage());
                $ok = false;
            }
        }
        $io->note('The video model is only tried on real videos: run `app:extract <source id> --sync` on an Instagram or TikTok link.');

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<string>|null model and inference profile ids, or null if the key can't list them */
    private function availableModels(SymfonyStyle $io): ?array
    {
        $ids = [];
        try {
            foreach (['inference-profiles?maxResults=1000' => ['inferenceProfileSummaries', 'inferenceProfileId'], 'foundation-models' => ['modelSummaries', 'modelId']] as $path => [$list, $field]) {
                $data = $this->httpClient->request('GET', \sprintf('https://bedrock.%s.amazonaws.com/%s', $this->region, $path), [
                    'auth_bearer' => trim($this->apiKey),
                    'timeout' => 20,
                ])->toArray();
                foreach (\is_array($data[$list] ?? null) ? $data[$list] : [] as $item) {
                    if (\is_array($item) && \is_string($item[$field] ?? null)) {
                        $ids[] = $item[$field];
                    }
                }
            }
        } catch (ExceptionInterface $e) {
            $io->note('Could not list models with this key ('.$e->getMessage().'). Skipping that check.');

            return null;
        }

        return array_values(array_unique($ids));
    }
}
