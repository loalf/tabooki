<?php

declare(strict_types=1);

namespace App\Food\Bedca;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which BEDCA food each catalog food is (or none), kept under var/bedca once proposed or set by
 * hand: what was reviewed in one run of app:foods:bedca is what the next imports.
 */
final readonly class BedcaPairs
{
    public function __construct(
        #[Autowire('%app.bedca_dir%')]
        private string $dir,
    ) {
    }

    /** @return array<string, int|null> BEDCA id by food key; null: it has none */
    public function all(): array
    {
        $file = $this->dir.'/pairs.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $pairs = [];
        foreach (\is_array($data) ? $data : [] as $key => $id) {
            if (\is_string($key) && (null === $id || \is_int($id))) {
                $pairs[$key] = $id;
            }
        }

        return $pairs;
    }

    /** @param array<string, int|null> $pairs merged into the saved ones */
    public function save(array $pairs): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        $all = array_merge($this->all(), $pairs);
        ksort($all);
        file_put_contents($this->dir.'/pairs.json', json_encode($all, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }
}
