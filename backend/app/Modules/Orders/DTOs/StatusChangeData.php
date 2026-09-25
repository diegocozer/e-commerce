<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** Extra data of an admin status transition (API.md §3.G.9). */
final readonly class StatusChangeData
{
    public function __construct(
        public ?string $note = null,
        public ?string $trackingCode = null,
        public ?string $trackingUrl = null,
        public ?string $carrierName = null,
        public ?string $pickedUpByName = null,
        public ?string $pickedUpByDocument = null,
    ) {}
}
