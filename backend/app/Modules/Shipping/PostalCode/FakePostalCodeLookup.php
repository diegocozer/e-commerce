<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;

/** In-memory lookup (driver `fake`, default in testing). Seeded with Vale do Itajaí/SC and SP CEPs. */
final class FakePostalCodeLookup implements PostalCodeLookup
{
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
        if (isset($this->notFound[$postalCode]) || ! isset($this->map[$postalCode])) {
            throw new PostalCodeNotFoundException('not_found');
        }

        return $this->map[$postalCode];
    }
}
