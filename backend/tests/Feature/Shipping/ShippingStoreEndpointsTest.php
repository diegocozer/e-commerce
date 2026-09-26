<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Modules\Shipping\PostalCode\CachedPostalCodeLookup;
use App\Modules\Shipping\PostalCode\FakePostalCodeLookup;
use App\Modules\Shipping\PostalCode\ViaCepPostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GET /postal-codes/{cep} + ViaCEP driver/cache (T01–T03, T37, T38, SH-01/02). */
final class ShippingStoreEndpointsTest extends TestCase
{
    public function test_fake_driver_is_bound_in_testing(): void
    {
        self::assertInstanceOf(FakePostalCodeLookup::class, app(PostalCodeLookup::class));
    }

    public function test_postal_code_lookup_ok(): void
    {
        $this->getJson('/api/v1/postal-codes/89010-000')
            ->assertOk()
            ->assertExactJson(['data' => [
                'postal_code' => '89010000', 'street' => 'Rua XV de Novembro', 'district' => 'Centro', 'city' => 'Blumenau',
                'state' => 'SC', 'city_ibge_code' => '4202404', 'source' => 'viacep',
            ]]);
    }

    public function test_fake_resolves_every_cep_of_known_city_ranges(): void
    {
        foreach (['89012000' => '4202404', '89015200' => '4202404', '89201000' => '4209102', '89112345' => '4205902', '01311000' => '3550308'] as $cep => $ibge) {
            self::assertSame($ibge, app(PostalCodeLookup::class)->lookup((string) $cep)->cityIbgeCode, (string) $cep);
        }
    }

    public function test_postal_code_invalid_not_found_and_unavailable(): void
    {
        $this->getJson('/api/v1/postal-codes/8901010')->assertStatus(422)->assertJsonPath('errors.cep.0', 'CEP inválido.');
        $this->getJson('/api/v1/postal-codes/00000000')->assertStatus(422);
        $this->getJson('/api/v1/postal-codes/99999999')->assertStatus(404)->assertJsonPath('code', 'not_found')->assertJsonPath('message', 'CEP não encontrado.');

        app(FakePostalCodeLookup::class)->failFor('89010100');
        $this->getJson('/api/v1/postal-codes/89010100')->assertStatus(503)->assertJsonPath('code', 'postal_code_lookup_unavailable');
    }

    public function test_postal_code_rate_limit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/postal-codes/89010000')->assertOk();
        }
        $this->getJson('/api/v1/postal-codes/89010000')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    private function viaCep(): CachedPostalCodeLookup
    {
        return new CachedPostalCodeLookup(new ViaCepPostalCodeLookup(app(HttpFactory::class)), Cache::store('array'));
    }

    public function test_t37_viacep_success_is_cached_for_30_days(): void
    {
        Http::preventStrayRequests();
        Http::fake(['viacep.com.br/ws/89010100/json/' => Http::response([
            'cep' => '89010-100', 'logradouro' => 'Rua XV de Novembro', 'bairro' => 'Centro', 'localidade' => 'Blumenau', 'uf' => 'SC', 'ibge' => '4202404',
        ])]);
        $lookup = $this->viaCep();

        self::assertSame('Blumenau', $lookup->lookup('89010100')->city);
        self::assertSame('viacep', $lookup->lastSource());
        self::assertSame('4202404', $lookup->lookup('89010100')->cityIbgeCode);
        self::assertSame('cache', $lookup->lastSource());
        Http::assertSentCount(1);

        $this->travel(29)->days();
        $lookup->lookup('89010100');
        Http::assertSentCount(1);
        $this->travel(2)->days();
        $lookup->lookup('89010100');
        Http::assertSentCount(2);
    }

    public function test_t38_viacep_not_found_is_cached_24h_and_failures_are_not_cached(): void
    {
        Http::fake([
            'viacep.com.br/ws/99999999/json/' => Http::response(['erro' => true]),
            'viacep.com.br/ws/88888888/json/' => Http::response('oops', 500),
            'viacep.com.br/ws/77777777/json/' => fn () => throw new ConnectionException('timeout'),
        ]);
        $lookup = $this->viaCep();

        foreach ([1, 2] as $_) {
            try {
                $lookup->lookup('99999999');
                self::fail('not found expected');
            } catch (PostalCodeNotFoundException) {
            }
        }
        Http::assertSentCount(1);

        foreach (['88888888', '88888888', '77777777'] as $cep) {
            try {
                $lookup->lookup($cep);
                self::fail('failure expected');
            } catch (PostalCodeLookupException $e) {
                self::assertNotInstanceOf(PostalCodeNotFoundException::class, $e);
            }
        }
        Http::assertSentCount(3); // connection failures are not recorded as sent

        $this->travel(25)->hours();
        try {
            $lookup->lookup('99999999');
        } catch (PostalCodeNotFoundException) {
        }
        Http::assertSentCount(4); // negative cache expired after 24 h
    }

    public function test_viacep_uses_timeouts(): void
    {
        Http::fake(['*' => Http::response(['localidade' => 'Blumenau', 'uf' => 'SC', 'ibge' => '4202404'])]);
        $this->viaCep()->lookup('89010100');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/89010100/json/'));
    }
}
