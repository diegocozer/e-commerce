<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\WhatsAppClient;
use App\Modules\Notifications\DTOs\WhatsAppResult;
use App\Shared\Support\Mask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** MVP stub: logs the message on the `notifications` channel with the phone masked. */
final class LogWhatsAppClient implements WhatsAppClient
{
    public function sendTemplate(string $toE164, string $template, array $params): WhatsAppResult
    {
        $id = 'log_'.Str::lower((string) Str::ulid());
        Log::channel('notifications')->info('whatsapp.stub', ['to' => Mask::phone($toE164), 'template' => $template, 'message_id' => $id]);

        return new WhatsAppResult(true, $id);
    }
}
