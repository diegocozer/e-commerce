<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Config;

/** Active zone with its locations. */
final readonly class ZoneConfig
{
    /**
     * @param  list<array{0: string, 1: string}>  $postalRanges  [start, end] 8 digits
     * @param  list<string>  $cityIbgeCodes
     * @param  list<string>  $states
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $postalRanges,
        public array $cityIbgeCodes,
        public array $states,
    ) {}

    public function hasCities(): bool
    {
        return $this->cityIbgeCodes !== [];
    }
}
