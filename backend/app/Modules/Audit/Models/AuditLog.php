<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Shared\Casts\JsonObjectCast;
use App\Shared\Domain\ActorType;
use Database\Factories\Audit\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Immutable audit record (DATABASE.md §3.10.1). A database trigger rejects
 * UPDATE/DELETE; the model refuses them as well. Write through
 * App\Shared\Audit\AuditLogger.
 *
 * @property int $id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_type', 'actor_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'old_values' => JsonObjectCast::class,
            'new_values' => JsonObjectCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  Builder<static>  $query */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('audit_logs is append-only.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('audit_logs is append-only.');
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
