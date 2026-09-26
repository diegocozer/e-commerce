<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;

/**
 * In-memory lookup (driver `fake`, default in testing/dev without network).
 * Exact entries first; otherwise any CEP inside a known city range resolves to
 * that city (covers every seeded address, e.g. Blumenau 89000000–89099999).
 * CEPs outside all ranges are "not found".
 */
final class FakePostalCodeLookup implements PostalCodeLookup
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> [start, end, city, UF, IBGE] */
    public const array CITY_RANGES = [
        ['89000000', '89099999', 'Blumenau', 'SC', '4202404'],
        ['89100000', '89109999', 'Pomerode', 'SC', '4213203'],
        ['89110000', '89119999', 'Gaspar', 'SC', '4205902'],
        ['89130000', '89139999', 'Indaial', 'SC', '4207502'],
        ['89200000', '89239999', 'Joinville', 'SC', '4209102'],
        ['88000000', '88099999', 'Florianópolis', 'SC', '4205407'],
        ['80000000', '82999999', 'Curitiba', 'PR', '4106902'],
        ['01000000', '05999999', 'São Paulo', 'SP', '3550308'],
        ['08000000', '08499999', 'São Paulo', 'SP', '3550308'],
    ];

    /** @var array<string, PostalCodeInfo> */
    private array $map = [];

    /** @var array<string, true> */
    private array $failing = [];

    /** @var array<string, true> */
    private array $notFound = [];

    private bool $failAll = false;

    public int $calls = 0;

    public function __construct()
    {
        foreach ([
            ['89010100', 'Rua XV de Novembro', 'Centro', 'Blumenau', 'SC', '4202404'],
            ['89010000', 'Rua XV de Novembro', 'Centro', 'Blumenau', 'SC', '4202404'],
            ['89010001', 'Rua XV de Novembro', 'Centro', 'Blumenau', 'SC', '4202404'],
            ['89110000', null, null, 'Gaspar', 'SC', '4205902'],
            ['89130000', null, null, 'Indaial', 'SC', '4207502'],
            ['89107000', null, null, 'Pomerode', 'SC', '4213203'],
            ['89201000', 'Rua do Príncipe', 'Centro', 'Joinville', 'SC', '4209102'],
            ['88010000', null, 'Centro', 'Florianópolis', 'SC', '4205407'],
            ['01310100', 'Avenida Paulista', 'Bela Vista', 'São Paulo', 'SP', '3550308'],
            ['80010000', null, 'Centro', 'Curitiba', 'PR', '4106902'],
        ] as [$cep, $street, $district, $city, $uf, $ibge]) {
            $this->add(new PostalCodeInfo($cep, $street, $district, $city, $uf, $ibge));
        }
    }

    public function add(PostalCodeInfo $info): self
    {
        $this->map[$info->postalCode] = $info;
        unset($this->failing[$info->postalCode], $this->notFound[$info->postalCode]);

        return $this;
    }

    public function failFor(string $postalCode): self
    {
        $this->failing[$postalCode] = true;

        return $this;
    }

    public function notFound(string $postalCode): self
    {
        $this->notFound[$postalCode] = true;

        return $this;
    }

    public function failAll(bool $fail = true): self
    {
        $this->failAll = $fail;

        return $this;
    }

    public function lookup(string $postalCode): PostalCodeInfo
    {
        $this->calls++;
        if ($this->failAll || isset($this->failing[$postalCode])) {
            throw new PostalCodeLookupException('timeout');
        }
        if (isset($this->notFound[$postalCode])) {
            throw new PostalCodeNotFoundException('not_found');
        }
        if (isset($this->map[$postalCode])) {
            return $this->map[$postalCode];
        }
        foreach (self::CITY_RANGES as [$start, $end, $city, $uf, $ibge]) {
            if (strcmp($start, $postalCode) <= 0 && strcmp($postalCode, $end) <= 0) {
                return new PostalCodeInfo($postalCode, null, null, $city, $uf, $ibge);
            }
        }

        throw new PostalCodeNotFoundException('not_found');
    }
}
