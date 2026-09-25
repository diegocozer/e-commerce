<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

/** Payer sent to the gateway (PIX requires name, e-mail and CPF/CNPJ). Never logged. */
final readonly class PayerData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $document = null,
    ) {}

    /** 'CPF' | 'CNPJ' | null */
    public function documentType(): ?string
    {
        return match (true) {
            $this->document === null => null,
            strlen($this->document) === 11 => 'CPF',
            default => 'CNPJ',
        };
    }
}
