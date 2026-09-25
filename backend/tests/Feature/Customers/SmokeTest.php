<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use Tests\Feature\Identity\Support\ApiTestCase;

final class SmokeTest extends ApiTestCase
{
    public function test_register_and_me(): void
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'type' => 'individual', 'name' => 'João da Silva', 'cpf' => '529.982.247-25', 'email' => 'Joao@Example.com',
            'phone' => '(47) 99999-0001', 'password' => 'segredo123', 'password_confirmation' => 'segredo123',
            'accept_terms' => true, 'terms_version' => '2026-01',
        ]);
        $r->dump();
        $this->getJson('/api/v1/me')->dump();
    }
}
