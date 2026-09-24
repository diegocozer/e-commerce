<?php

declare(strict_types=1);

namespace App\Shared\Domain\Documents;

use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Support\Mask;
use JsonSerializable;

/** Validated CPF or CNPJ, stored without mask (CNPJ upper-case alphanumeric). */
final readonly class TaxDocument implements JsonSerializable
{
    private function __construct(public TaxDocumentType $type, private string $value) {}

    public static function cpf(string $raw): self
    {
        if (! Cpf::isValid($raw)) {
            throw InvalidValue::because('Invalid CPF.');
        }

        return new self(TaxDocumentType::Cpf, Cpf::normalize($raw));
    }

    public static function cnpj(string $raw): self
    {
        if (! Cnpj::isValid($raw)) {
            throw InvalidValue::because('Invalid CNPJ.');
        }

        return new self(TaxDocumentType::Cnpj, Cnpj::normalize($raw));
    }

    /** Detects the type by length after normalization (11 = CPF, 14 = CNPJ). */
    public static function fromString(string $raw): self
    {
        return strlen(Cnpj::normalize($raw)) === 11 ? self::cpf($raw) : self::cnpj($raw);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function formatted(): string
    {
        return $this->type === TaxDocumentType::Cpf ? Cpf::format($this->value) : Cnpj::format($this->value);
    }

    public function masked(): string
    {
        return $this->type === TaxDocumentType::Cpf ? Mask::cpf($this->value) : Mask::cnpj($this->value);
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
