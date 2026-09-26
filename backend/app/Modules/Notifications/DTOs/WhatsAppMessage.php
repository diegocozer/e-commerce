<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/** Returned by Notification::toWhatsApp(). */
final readonly class WhatsAppMessage
{
    /** @param array<string, string> $params */
    public function __construct(
        public string $template,
        public array $params = [],
    ) {}
}
