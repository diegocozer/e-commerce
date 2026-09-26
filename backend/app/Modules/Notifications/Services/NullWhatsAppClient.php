<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\WhatsAppClient;
use App\Modules\Notifications\DTOs\WhatsAppResult;

/** Discards messages (tests). */
final class NullWhatsAppClient implements WhatsAppClient
{
    /** @var list<array{to: string, template: string, params: array<string, string>}> */
    public array $sent = [];

    public function sendTemplate(string $toE164, string $template, array $params): WhatsAppResult
    {
        $this->sent[] = ['to' => $toE164, 'template' => $template, 'params' => $params];

        return new WhatsAppResult(false, null, 'null driver');
    }
}
