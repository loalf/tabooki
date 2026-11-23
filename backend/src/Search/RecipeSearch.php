<?php

declare(strict_types=1);

namespace App\Search;

use App\Doctrine\VectorType;
use App\Entity\ExtractionRun;
use App\Entity\Household;
use App\Library\SearchText;
use App\Llm\Embedding;
use App\Llm\EmbeddingClient;
use App\Llm\LlmException;
use App\Llm\Pricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Search by meaning: "algo ligero con garbanzos" finds the chickpea salad though no recipe has
 * those words; only what is close enough comes back, so a search can find nothing. The query is embedded (cached for a day: the same words give the same vector) and
 * compared with every recipe's embedding of the household, exact, no index: household scale.
 * Words after a dash ("-chorizo") leave recipes out as in the library search, and aren't embedded.
 */
final readonly class RecipeSearch
{
    public const int LIMIT = 12;

    /**
     * What counts as found, for Cohere Embed v4 (scores are the model's own: another one needs its
     * own). Below the floor it's about something else ("chocolate dessert" in a library without
     * any); well below the best match it's only loosely related.
     */
    private const float MIN_SCORE = 0.35;
    private const float BELOW_BEST = 0.12;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmbeddingClient $embeddings,
        private CacheInterface $cache,
        private Pricing $pricing,
        #[Autowire('%env(BEDROCK_EMBEDDING_MODEL)%')]
        private string $model,
    ) {
    }

    /**
     * @return list<array{id: string, score: float}> most alike first; score is the cosine similarity
     *
     * @throws LlmException
     */
    public function search(Household $household, string $query): array
    {
        $words = SearchText::words($query);
        $without = array_values(array_filter(array_map(
            static fn (string $w): string => ltrim($w, '-'),
            array_filter($words, static fn (string $w): bool => str_starts_with($w, '-')),
        )));
        $wanted = trim(implode(' ', array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn (string $w): bool => !str_starts_with($w, '-'))));
        if ('' === $wanted) {
            return [];
        }

        $vector = $this->queryVector($household, $wanted);
        $conditions = ['r.household_id = :household', 'e.model = :model'];
        $parameters = ['household' => $household->getId()->toRfc4122(), 'model' => $this->model, 'query' => VectorType::literal($vector)];
        foreach (\array_slice($without, 0, 10) as $i => $word) {
            if (mb_strlen($word) > 3 && str_ends_with($word, 's')) {
                $word = substr($word, 0, -1);
            }
            $like = addcslashes($word, '%_\\').'%';
            $conditions[] = "' ' || r.search_text NOT LIKE :space{$i} AND r.search_text NOT LIKE :bracket{$i}";
            $parameters['space'.$i] = '% '.$like;
            $parameters['bracket'.$i] = '%('.$like;
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT r.id, 1 - (e.vector <=> CAST(:query AS vector)) AS score
             FROM recipe_embedding e JOIN recipe r ON r.id = e.recipe_id
             WHERE '.implode(' AND ', $conditions).'
             ORDER BY e.vector <=> CAST(:query AS vector)
             LIMIT '.self::LIMIT,
            $parameters,
        );

        $found = [];
        foreach ($rows as $row) {
            if (\is_string($row['id'] ?? null) && is_numeric($row['score'] ?? null)) {
                $found[] = ['id' => $row['id'], 'score' => round((float) $row['score'], 3)];
            }
        }
        $best = $found[0]['score'] ?? 0.0;

        return array_values(array_filter($found, static fn (array $r): bool => $r['score'] >= self::MIN_SCORE && $r['score'] >= $best - self::BELOW_BEST));
    }

    /** @return list<float> */
    private function queryVector(Household $household, string $query): array
    {
        $key = 'search_'.hash('xxh128', $this->model."\n".mb_strtolower($query));

        /** @var list<float> $vector */
        $vector = $this->cache->get($key, function (ItemInterface $item) use ($household, $query): array {
            $item->expiresAfter(86400);
            $started = hrtime(true);
            $embedding = $this->embeddings->embed($this->model, $query, EmbeddingClient::QUERY);
            $this->entityManager->persist(new ExtractionRun(
                $household, null, 'search', 'bedrock', $embedding->model, $embedding->tokens, 0,
                $this->pricing->cost($embedding->model, $embedding->tokens, 0), true, null, (int) ((hrtime(true) - $started) / 1_000_000),
            ));
            $this->entityManager->flush();

            return Embedding::DIMENSIONS === \count($embedding->vector) ? $embedding->vector : [];
        });

        return $vector;
    }
}
