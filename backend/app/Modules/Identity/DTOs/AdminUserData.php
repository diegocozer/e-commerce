<?php

declare(strict_types=1);

namespace App\Modules\Identity\DTOs;

final readonly class AdminUserData
{
    /** @param  list<string>  $roles */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $isActive,
        public array $roles,
    ) {}
}
