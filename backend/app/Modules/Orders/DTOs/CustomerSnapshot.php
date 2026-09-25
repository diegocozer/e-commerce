<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Customers\Enums\CustomerType;

/** Buyer snapshot stored on the order (orders.customer_*). `$document`: digits (CPF) or CNPJ. */
final readonly class CustomerSnapshot
{
    public function __construct(
        public CustomerType $type,
        public string $name,
        public string $email,
        public string $document,
        public ?string $phone = null,
        public ?string $companyName = null,
        public ?string $stateRegistration = null,
    ) {}
}
