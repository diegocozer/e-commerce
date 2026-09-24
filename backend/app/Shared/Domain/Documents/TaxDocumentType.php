<?php

declare(strict_types=1);

namespace App\Shared\Domain\Documents;

enum TaxDocumentType: string
{
    case Cpf = 'cpf';
    case Cnpj = 'cnpj';
}
