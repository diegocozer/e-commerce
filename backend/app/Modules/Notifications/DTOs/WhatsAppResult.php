<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

final readonly class WhatsAppResult
{
    public function __construct(
        public bool $sent,
        public ?string $messageId = null,
        public ?string $error = null,
    ) {}
}
