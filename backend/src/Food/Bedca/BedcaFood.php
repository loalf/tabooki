<?php

declare(strict_types=1);

namespace App\Food\Bedca;

/** A food of BEDCA: its id and names ("Garbanzo, en conserva" / "Chickpea, canned"). */
final readonly class BedcaFood
{
    public function __construct(
        public int $id,
        public string $nameEs,
        public string $nameEn,
    ) {
    }
}
