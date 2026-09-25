<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

use App\Modules\Customers\Enums\CustomerType;

final readonly class CustomerData
{
    public function __construct(
        public int $id,
        public string $uuid,
        public CustomerType $type,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $cpf,
        public ?int $companyId,
        public ?string $companyLegalName,
        public ?string $companyTradeName,
        public ?string $cnpj,
        public ?string $stateRegistration,
        public bool $stateRegistrationExempt,
        public bool $isActive,
        public bool $marketingOptIn,
    ) {}

    /** CPF (PF) or CNPJ (PJ) — the fiscal document of the order. */
    public function document(): ?string
    {
        return $this->type === CustomerType::Company ? $this->cnpj : $this->cpf;
    }
}
