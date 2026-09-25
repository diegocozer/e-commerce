<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Documents\Cnpj;
use App\Shared\Domain\Documents\Cpf;
use App\Shared\Domain\Documents\TaxDocument;
use App\Shared\Domain\Documents\TaxDocumentType;
use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Domain\PostalCode;
use Database\Factories\Support\BrazilianDocuments;
use PHPUnit\Framework\TestCase;

final class DocumentsTest extends TestCase
{
    public function test_cpf_validation(): void
    {
        self::assertTrue(Cpf::isValid('52998224725'));
        self::assertTrue(Cpf::isValid('529.982.247-25'));
        self::assertFalse(Cpf::isValid('52998224724'));
        self::assertFalse(Cpf::isValid('11111111111'));
        self::assertFalse(Cpf::isValid('5299822472'));
        self::assertSame('529.982.247-25', Cpf::format('52998224725'));
        self::assertTrue(Cpf::isValid(BrazilianDocuments::cpf()));
    }

    public function test_numeric_cnpj_validation(): void
    {
        self::assertTrue(Cnpj::isValid('11222333000181'));
        self::assertTrue(Cnpj::isValid('11.222.333/0001-81'));
        self::assertFalse(Cnpj::isValid('11222333000182'));
        self::assertFalse(Cnpj::isValid('00000000000000'));
        self::assertSame('11.222.333/0001-81', Cnpj::format('11222333000181'));
        self::assertTrue(Cnpj::isValid(BrazilianDocuments::cnpj()));
    }

    public function test_alphanumeric_cnpj_validation(): void
    {
        // Official example published by Receita Federal: 12.ABC.345/01DE-35
        self::assertTrue(Cnpj::isValid('12.ABC.345/01DE-35'));
        self::assertTrue(Cnpj::isValid('12abc34501de35'));
        self::assertSame('12ABC34501DE35', Cnpj::normalize('12.abc.345/01de-35'));
        self::assertFalse(Cnpj::isValid('12ABC34501DE36'));
        self::assertFalse(Cnpj::isValid('12ABC34501DEA5'));
        self::assertTrue(Cnpj::isAlphanumeric('12ABC34501DE35'));
        self::assertTrue(Cnpj::isValid(BrazilianDocuments::alphanumericCnpj()));
    }

    public function test_tax_document(): void
    {
        $cpf = TaxDocument::fromString('529.982.247-25');
        self::assertSame(TaxDocumentType::Cpf, $cpf->type);
        self::assertSame('***.982.247-**', $cpf->masked());

        $cnpj = TaxDocument::fromString('12.abc.345/01de-35');
        self::assertSame(TaxDocumentType::Cnpj, $cnpj->type);
        self::assertSame('12ABC34501DE35', $cnpj->value());
        self::assertSame('**.ABC.345/01DE-**', $cnpj->masked());

        $this->expectException(InvalidValue::class);
        TaxDocument::cpf('12345678900');
    }

    public function test_postal_code_normalization(): void
    {
        self::assertSame('89010001', PostalCode::normalize('89010-001'));
        self::assertSame('89010001', PostalCode::normalize(' 89.010-001 '));
        self::assertSame('89010001', PostalCode::normalize('89010001'));
        self::assertNull(PostalCode::normalize('8901000'));
        self::assertNull(PostalCode::normalize('00000-000'));
        self::assertNull(PostalCode::normalize('89010-00A'));
        self::assertSame('89010-001', PostalCode::fromString('89010001')->formatted());

        $this->expectException(InvalidValue::class);
        PostalCode::fromString('123');
    }
}
