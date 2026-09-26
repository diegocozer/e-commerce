<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** GET /admin/failed-jobs (ARCHITECTURE §10.4). Payloads/traces are never exposed. */
final class FailedJobController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'queue' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = DB::table('failed_jobs')
            ->when(isset($validated['queue']), fn ($q) => $q->where('queue', $validated['queue']))
            ->orderByDesc('failed_at')->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();

        $page->setCollection($page->getCollection()->map(static function (object $row): array {
            $payload = json_decode((string) $row->payload, true);
            $firstLine = strtok((string) $row->exception, "\n");

            return [
                'uuid' => (string) $row->uuid,
                'queue' => (string) $row->queue,
                'job' => is_array($payload) ? (string) ($payload['displayName'] ?? 'unknown') : 'unknown',
                'exception_summary' => Str::limit($firstLine === false ? '' : $firstLine, 300),
                'failed_at' => CarbonImmutable::parse((string) $row->failed_at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }));

        return JsonResource::collection($page);
    }
}
