<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Contracts\WhatsAppClient;
use App\Modules\Notifications\DTOs\WhatsAppMessage;
use Illuminate\Notifications\Notification;

/** Calls $notification->toWhatsApp($notifiable) and sends it through the WhatsAppClient. */
final class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp') || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }
        $phone = $notifiable->routeNotificationFor('whatsapp', $notification);
        $phone = is_string($phone) ? $phone : ($notifiable->phone ?? null);
        if (! is_string($phone) || $phone === '') {
            return;
        }

        /** @var WhatsAppMessage|null $message */
        $message = $notification->toWhatsApp($notifiable);
        if ($message === null) {
            return;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $this->client->sendTemplate(str_starts_with($digits, '55') ? '+'.$digits : '+55'.$digits, $message->template, $message->params);
    }
}
