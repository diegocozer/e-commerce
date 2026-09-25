<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Services\DatabaseAuditLogger;
use App\Shared\Audit\AuditLogger;
use App\Shared\Providers\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected array $listen = [];

    /** @var array<class-string, class-string> */
    protected array $policies = [];

    public function register(): void
    {
        parent::register();

        // Not a singleton: it depends on the current request (IP, user agent).
        $this->app->bind(AuditLogger::class, DatabaseAuditLogger::class);
    }
}
