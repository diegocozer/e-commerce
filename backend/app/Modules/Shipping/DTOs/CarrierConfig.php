<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Runtime configuration handed to a carrier driver (SHIPPING.md §4.5). */
final readonly class CarrierConfig
{
    /**
     * @param  array<string, mixed>  $settings  non-sensitive
     * @param  array<string, mixed>  $credentials  decrypted; never logged
     */
    public function __construct(
        public int $carrierId,
        public string $code,
        public string $driver,
        public string $name,
        public array $settings,
        public array $credentials,
        public int $timeoutMs,
        public int $cubicDivisor,
        public string $originPostalCode,
        public bool $isActive = true,
    ) {}
}
