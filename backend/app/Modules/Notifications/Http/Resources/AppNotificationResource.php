<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** AppNotification (API.md §2.11). @mixin DatabaseNotification */
class AppNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $n */
        $n = $this->resource;
        $data = is_array($n->data) ? $n->data : [];

        return [
            'id' => $n->id,
            'type' => (string) ($data['type'] ?? $n->type),
            'title' => (string) ($data['title'] ?? ''),
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'read_at' => $n->read_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $n->created_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
