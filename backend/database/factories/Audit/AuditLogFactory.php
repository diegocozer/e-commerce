<?php

declare(strict_types=1);

namespace Database\Factories\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Domain\ActorType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'actor_type' => ActorType::System,
            'actor_id' => null,
            'action' => 'setting.updated',
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => ['value' => 1],
            'new_values' => ['value' => 2],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_id' => (string) Str::ulid(),
        ];
    }
}
