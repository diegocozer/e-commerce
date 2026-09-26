<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

final readonly class AdminLoginFailed
{
    public function __construct(public ?int $adminUserId, public string $emailHash, public ?string $ip) {}
}
