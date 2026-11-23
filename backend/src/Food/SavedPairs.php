<?php

declare(strict_types=1);

namespace App\Food;

/**
 * Which food of a composition table each catalog food is (or none), kept in a JSON file once
 * proposed or set by hand: what one run of an import showed is what the next imports.
 */
final readonly class SavedPairs
{
    public function __construct(private string $file)
    {
    }

    /** @return array<string, int|null> the table's id by food key; null: it has none */
    public function all(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
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
        if ([] === $pairs) {
            return;
        }
        if (!is_dir(\dirname($this->file))) {
            mkdir(\dirname($this->file), 0775, true);
        }
        $all = array_merge($this->all(), $pairs);
        ksort($all);
        file_put_contents($this->file, json_encode($all, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * `--pair key=id` options, read.
     *
     * @param list<string> $given
     *
     * @return array<string, int|null>|string the pairs, or what's wrong with one
     */
    public static function parse(array $given): array|string
    {
        $pairs = [];
        foreach ($given as $one) {
            [$key, $id] = array_pad(explode('=', $one, 2), 2, '');
            if ('' === $key || !ctype_digit($id)) {
                return \sprintf('"%s": give key=id (0 for none).', $one);
            }
            $pairs[$key] = '0' === $id ? null : (int) $id;
        }

        return $pairs;
    }
}
