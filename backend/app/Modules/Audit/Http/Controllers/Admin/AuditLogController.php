<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers\Admin;

use App\Modules\Audit\Http\Requests\AuditLogIndexRequest;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Support\AuditLabels;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** GET /admin/audit-logs* (API.md §3.G.14) — read-only. */
final class AuditLogController
{
    private const string TZ = 'America/Sao_Paulo';

    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $query = AuditLog::query();

        foreach (['actor_type', 'actor_id', 'auditable_type', 'auditable_id', 'request_id'] as $field) {
            if (isset($f[$field])) {
                $query->where($field, $f[$field]);
            }
        }
        if (isset($f['action'])) {
            $query->where('action', 'like', addcslashes((string) $f['action'], '%_\\').'%');
        }
        if (isset($f['date_from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($f['date_from'], self::TZ)->startOfDay()->utc());
        }
        if (isset($f['date_to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($f['date_to'], self::TZ)->addDay()->startOfDay()->utc());
        }
        $asc = ($f['sort'] ?? '-created_at') === 'created_at';
        $query->orderBy('created_at', $asc ? 'asc' : 'desc')->orderBy('id', $asc ? 'asc' : 'desc');

        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();
        $labels = AuditLabels::resolve(collect($page->items()));
        $page->setCollection($page->getCollection()->map(fn (AuditLog $log): array => self::present($log, $labels)));

        return JsonResource::collection($page);
    }

    public function show(int $auditLog): JsonResponse
    {
        $log = AuditLog::query()->findOrFail($auditLog);

        return new JsonResponse(['data' => self::present($log, AuditLabels::resolve(new Collection([$log])))]);
    }

    /**
     * @param  array{actors: array<string, string>, auditables: array<string, string>}  $labels
     * @return array<string, mixed>
     */
    private static function present(AuditLog $log, array $labels): array
    {
        $type = AuditLabels::typeValue($log->actor_type);

        return [
            'id' => $log->id,
            'actor' => ['type' => $type, 'id' => $log->actor_id, 'name' => $labels['actors']["{$type}:{$log->actor_id}"] ?? null],
            'action' => $log->action,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'auditable_label' => $log->auditable_type === null ? null : ($labels['auditables']["{$log->auditable_type}:{$log->auditable_id}"] ?? null),
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'request_id' => $log->request_id,
            'created_at' => self::iso($log->created_at),
        ];
    }

    private static function iso(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
