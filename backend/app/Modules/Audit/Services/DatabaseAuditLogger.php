<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\Middleware\RequestId;
use App\Shared\Support\SensitiveData;
use Illuminate\Http\Request;

/**
 * Writes audit entries to the immutable `audit_logs` table, enriching them
 * with IP, user agent and request id. Secrets are redacted and documents
 * masked before persisting (ADR-016 / SECURITY.md §19).
 */
final class DatabaseAuditLogger implements AuditLogger
{
    public function __construct(private readonly Request $request) {}

    public function record(AuditEntry $entry): void
    {
        $userAgent = $this->request->userAgent();

        AuditLog::query()->create([
            'actor_type' => $entry->actor->type,
            'actor_id' => $entry->actor->id,
            'action' => $entry->action,
            'auditable_type' => $entry->auditableType,
            'auditable_id' => $entry->auditableId,
            'old_values' => $entry->oldValues === null ? null : SensitiveData::redact($entry->oldValues),
            'new_values' => $entry->newValues === null ? null : SensitiveData::redact($entry->newValues),
            'ip' => $this->request->ip(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'request_id' => RequestId::current(),
        ]);
    }
}
