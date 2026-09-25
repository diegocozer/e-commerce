<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Shipping\Enums\UnavailableReason;

final readonly class UnavailableMethod
{
    public function __construct(
        public int $methodId,
        public string $methodCode,
        public UnavailableReason $reason,
        public ?string $detail = null,   // technical text for admin/log; never in the public API
    ) {}

    /** @return array{method_id: int, method_code: string, reason: string, detail: string|null} */
    public function toArray(): array
    {
        return ['method_id' => $this->methodId, 'method_code' => $this->methodCode, 'reason' => $this->reason->value, 'detail' => $this->detail];
    }

    /** @param  array<string, mixed>  $d */
    public static function fromArray(array $d): self
    {
        return new self((int) $d['method_id'], (string) $d['method_code'], UnavailableReason::from((string) $d['reason']), $d['detail'] ?? null);
    }
}
