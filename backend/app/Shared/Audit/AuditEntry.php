<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Domain\ActorRef;

/**
 * One audit record (ADR-016). `auditableType` is a morph-map alias
 * (e.g. "product", "order"), never a class name. Values contain only the
 * changed fields; the logger redacts secrets and masks documents anyway.
 */
final readonly class AuditEntry
{
    /**
     * @param  string  $action  "{entity}.{verb}", e.g. "product.updated", "admin_user.login"
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function __construct(
        public ActorRef $actor,
        public string $action,
        public ?string $auditableType = null,
        public ?int $auditableId = null,
        public ?array $oldValues = null,
        public ?array $newValues = null,
    ) {}

    /**
     * Builds an entry with only the attributes that actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function diff(ActorRef $actor, string $action, ?string $auditableType, ?int $auditableId, array $before, array $after): self
    {
        $changedOld = [];
        $changedNew = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $changedOld[$key] = $old;
                $changedNew[$key] = $new;
            }
        }

        return new self($actor, $action, $auditableType, $auditableId, $changedOld ?: null, $changedNew ?: null);
    }
}
