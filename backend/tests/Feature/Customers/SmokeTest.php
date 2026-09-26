<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Identity\Enums\AdminRole;
use Tests\Feature\Identity\Support\ApiTestCase;

final class SmokeTest extends ApiTestCase
{
    public function test_admin(): void
    {
        $a = $this->admin([], AdminRole::SuperAdmin, ['email' => 'root@example.com']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'root@example.com', 'password' => 'password'])->dump();
        $this->getJson('/api/v1/admin/roles')->dump();
        $this->postJson('/api/v1/admin/users', ['name' => 'Novo Adm', 'email' => 'n@example.com', 'roles' => ['seller']])->dump();
    }
}
