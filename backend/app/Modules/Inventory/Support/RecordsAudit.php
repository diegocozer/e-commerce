<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;

/** Explicit audit records inside actions (ADR-016; diff only of changed fields). */
trait RecordsAudit
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function audit(string $action, string $type, ?int $id, array $before = [], array $after = []): void
    {
        app(AuditLogger::class)->record(AuditEntry::diff(ActorRef::current(), $action, $id !== null ? $type : null, $id, $before, $after));
    }
}
