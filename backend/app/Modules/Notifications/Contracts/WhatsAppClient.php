<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTOs\WhatsAppResult;

/** WhatsApp provider (ARCHITECTURE.md §7.5). MVP drivers: `log` and `null`. */
interface WhatsAppClient
{
    /** @param array<string, string> $params */
    public function sendTemplate(string $toE164, string $template, array $params): WhatsAppResult;
}
