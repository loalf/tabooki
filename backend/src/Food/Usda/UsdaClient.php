<?php

declare(strict_types=1);

namespace App\Food\Usda;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * USDA FoodData Central's API (public domain, CC0; docs/INGREDIENT-KNOWLEDGE.md, "Data
 * licences"), with a data.gov key (USDA_API_KEY; DEMO_KEY allows only about 10 requests an hour).
 * Answers are kept under var/tables/usda and asked once.
 */
final readonly class UsdaClient
{
    private const string API = 'https://api.nal.usda.gov/fdc/v1';
    private const int PAUSE_MICROSECONDS = 250_000;

    public function __construct(
        #[Autowire(service: 'usda.http_client')]
        private HttpClientInterface $httpClient,
        #[Autowire('%env(USDA_API_KEY)%')]
        private string $apiKey,
        #[Autowire('%app.usda_dir%')]
        private string $dir,
    ) {
    }

    /**
     * The best few for these words: raw ingredients (Foundation, SR Legacy) and prepared foods
     * (FNDDS), as separate lists so the dishes don't crowd out the ingredients; products' labels
     * (Branded) only when both have nothing.
     *
     * @return list<UsdaFood>
     */
    public function search(string $query): array
    {
        $found = [
            ...$this->searchIn($query, ['Foundation', 'SR Legacy'], 8),
            ...$this->searchIn($query, ['Survey (FNDDS)'], 4),
        ];

        return [] !== $found ? $found : $this->searchIn($query, ['Branded'], 8);
    }

    /**
     * @param list<string> $dataTypes
     *
     * @return list<UsdaFood>
     */
    private function searchIn(string $query, array $dataTypes, int $size): array
    {
        // By POST, as JSON: in a URL, the client sends the parentheses of "Survey (FNDDS)" unencoded, and USDA refuses them.
        $data = $this->call('search-'.hash('xxh128', implode(',', $dataTypes)."\n".mb_strtolower($query)), 'POST', '/foods/search', [
            'json' => ['query' => $query, 'dataType' => $dataTypes, 'pageSize' => $size],
        ]);
        $foods = [];
        foreach (\is_array($data['foods'] ?? null) ? $data['foods'] : [] as $item) {
            $food = \is_array($item) ? UsdaFood::fromArray($item) : null;
            if (null !== $food) {
                $this->keep($food);
                $foods[] = $food;
            }
        }

        return $foods;
    }

    public function food(int $fdcId): ?UsdaFood
    {
        $file = $this->dir.'/food-'.$fdcId.'.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            return \is_array($data) ? UsdaFood::fromArray($data) : null;
        }
        $data = $this->call(null, 'GET', '/food/'.$fdcId, ['query' => ['format' => 'abridged']]);
        $food = [] === $data ? null : UsdaFood::fromArray($data);
        if (null !== $food) {
            $this->keep($food);
        }

        return $food;
    }

    private function keep(UsdaFood $food): void
    {
        $this->write($this->dir.'/food-'.$food->fdcId.'.json', json_encode($food->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed> empty when the food doesn't exist
     */
    private function call(?string $cache, string $method, string $path, array $options): array
    {
        $file = null === $cache ? null : $this->dir.'/'.$cache.'.json';
        if (null !== $file && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            return \is_array($data) ? $data : [];
        }
        usleep(self::PAUSE_MICROSECONDS);
        $options['query'] = ['api_key' => '' === trim($this->apiKey) ? 'DEMO_KEY' : trim($this->apiKey)] + (\is_array($options['query'] ?? null) ? $options['query'] : []);
        $response = $this->httpClient->request($method, self::API.$path, $options + ['timeout' => 30]);
        $status = $response->getStatusCode();
        if (429 === $status) {
            throw new \RuntimeException('USDA\'s rate limit reached: wait an hour, or set USDA_API_KEY to your own data.gov key (https://api.data.gov/signup/).');
        }
        if (404 === $status && 'GET' === $method) {
            return [];
        }
        if ($status >= 400) {
            // Never read as "nothing found": that would quietly send every food to products' labels.
            throw new \RuntimeException(\sprintf('USDA refused %s %s (HTTP %d).', $method, $path, $status));
        }
        $text = $response->getContent();
        if (null !== $file) {
            $this->write($file, $text);
        }
        $data = json_decode($text, true);

        return \is_array($data) ? $data : [];
    }

    private function write(string $file, string $text): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        file_put_contents($file, $text);
    }
}
