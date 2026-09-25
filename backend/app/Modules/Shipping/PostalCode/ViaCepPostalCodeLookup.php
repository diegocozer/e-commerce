<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/** GET {base}/{cep}/json/ — timeout 3 s, connect 2 s, no retry (SHIPPING.md §4.8). */
final class ViaCepPostalCodeLookup implements PostalCodeLookup
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl = 'https://viacep.com.br/ws',
        private readonly int $timeoutSeconds = 3,
    ) {}

    public function lookup(string $postalCode): PostalCodeInfo
    {
        try {
            $response = $this->http->acceptJson()
                ->connectTimeout(min(2, $this->timeoutSeconds))
                ->timeout($this->timeoutSeconds)
                ->get(rtrim($this->baseUrl, '/')."/{$postalCode}/json/");
        } catch (ConnectionException $e) {
            throw new PostalCodeLookupException('timeout', 0, $e);
        } catch (Throwable $e) {
            throw new PostalCodeLookupException('network', 0, $e);
        }

        if ($response->status() === 400 || $response->status() === 404) {
            throw new PostalCodeNotFoundException('not_found');
        }
        if (! $response->successful()) {
            throw new PostalCodeLookupException('http_'.$response->status());
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new PostalCodeLookupException('invalid_json');
        }
        if (($data['erro'] ?? false) === true || ($data['erro'] ?? null) === 'true') {
            throw new PostalCodeNotFoundException('not_found');
        }

        $city = $data['localidade'] ?? null;
        $state = $data['uf'] ?? null;
        $ibge = $data['ibge'] ?? null;
        if (! is_string($city) || $city === '' || ! is_string($state) || strlen($state) !== 2 || ! is_string($ibge) || preg_match('/^\d{7}$/', $ibge) !== 1) {
            throw new PostalCodeLookupException('invalid_json');
        }

        $street = is_string($data['logradouro'] ?? null) && $data['logradouro'] !== '' ? $data['logradouro'] : null;
        $district = is_string($data['bairro'] ?? null) && $data['bairro'] !== '' ? $data['bairro'] : null;

        return new PostalCodeInfo($postalCode, $street, $district, $city, strtoupper($state), $ibge);
    }
}
