<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** webhook_events.status. */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
