<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

final readonly class CarrierInfo
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $serviceCode,
        public ?string $serviceName,
    ) {}

    /** @return array{code: string, name: string, service_code: string|null, service_name: string|null} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'service_code' => $this->serviceCode, 'service_name' => $this->serviceName];
    }

    /** @param  array<string, mixed>|null  $data */
    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self((string) $data['code'], (string) $data['name'], $data['service_code'] ?? null, $data['service_name'] ?? null);
    }
}
