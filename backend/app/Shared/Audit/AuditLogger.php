<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Contract used by every module to record audit logs explicitly inside its
 * Actions (ARCHITECTURE.md §2.4 Audit). Implemented by the Audit module
 * (App\Modules\Audit\Services\DatabaseAuditLogger).
 */
interface AuditLogger
{
    public function record(AuditEntry $entry): void;
}
