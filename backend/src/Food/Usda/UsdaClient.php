<?php

declare(strict_types=1);

namespace App\Food\Usda;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * USDA FoodData Central's API (public domain, CC0; docs/INGREDIENT-KNOWLEDGE.md, "Data
 * licences"), with a data.gov key (USDA_API_KEY; DEMO_KEY allows only about 10 requests an hour).
 * Searches the generic datasets first (Foundation, SR Legacy, FNDDS) and products' labels
 * (Branded) only when they have nothing. Answers are kept under var/usda and asked once.
 */
final readonly class UsdaClient
{
    private const string API = 'https://api.nal.usda.gov/fdc/v1';
    private const string GENERIC = 'Foundation,SR Legacy,Survey (FNDDS)';
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

    /** @return list<UsdaFood> the best few for these words, generic foods when there are any */
    public function search(string $query, int $size = 8): array
    {
        foreach ([self::GENERIC, 'Branded'] as $dataType) {
            $data = $this->get('search-'.hash('xxh128', $dataType."\n".mb_strtolower($query)), '/foods/search', [
                'query' => $query, 'dataType' => $dataType, 'pageSize' => $size,
            ]);
            $foods = [];
            foreach (\is_array($data['foods'] ?? null) ? $data['foods'] : [] as $item) {
                $food = \is_array($item) ? UsdaFood::fromArray($item) : null;
                if (null !== $food) {
                    $this->keep($food);
                    $foods[] = $food;
                }
            }
            if ([] !== $foods) {
                return $foods;
            }
        }

        return [];
    }

    public function food(int $fdcId): ?UsdaFood
    {
        $file = $this->dir.'/food-'.$fdcId.'.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            return \is_array($data) ? UsdaFood::fromArray($data) : null;
        }
        $data = $this->get(null, '/food/'.$fdcId, ['format' => 'abridged']);
        $food = UsdaFood::fromArray($data);
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
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    private function get(?string $cache, string $path, array $query): array
    {
        $file = null === $cache ? null : $this->dir.'/'.$cache.'.json';
        if (null !== $file && is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);

            return \is_array($data) ? $data : [];
        }
        usleep(self::PAUSE_MICROSECONDS);
        // Encoded here, fully: USDA's server refuses the parentheses of "Survey (FNDDS)" left as they are.
        $url = self::API.$path.'?'.http_build_query(['api_key' => '' === trim($this->apiKey) ? 'DEMO_KEY' : trim($this->apiKey)] + $query, '', '&', \PHP_QUERY_RFC3986);
        $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
        $status = $response->getStatusCode();
        if (429 === $status) {
            throw new \RuntimeException('USDA\'s rate limit reached: wait an hour, or set USDA_API_KEY to your own data.gov key (https://api.data.gov/signup/).');
        }
        if (400 === $status || 404 === $status) {
            return []; // a query it won't take, or an id it doesn't have: nothing found
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
