<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Exceptions\StaleResource;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/** Audit + config-change log + optimistic concurrency for the shipping admin actions. */
final class ShippingAdminSupport
{
    public function __construct(private readonly AuditLogger $audit, private readonly ShippingConfigRepository $config) {}

    public static function iso(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public function assertFresh(Model $model, ?string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === null || $model->getAttribute('updated_at') === null) {
            return;
        }
        $current = CarbonImmutable::instance($model->getAttribute('updated_at'))->utc();
        if ($current->format('Y-m-d H:i:s') !== CarbonImmutable::parse($expectedUpdatedAt)->utc()->format('Y-m-d H:i:s')) {
            throw new StaleResource(self::iso($current));
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(ActorRef $actor, string $entity, string $verb, int $id, array $before, array $after): void
    {
        unset($before['credentials'], $after['credentials'], $before['updated_at'], $after['updated_at'], $before['created_at'], $after['created_at']);
        $this->audit->record(AuditEntry::diff($actor, "{$entity}.{$verb}", $entity, $id, $before, $after));
        Log::channel('shipping')->info('shipping.config.changed', [
            'entity' => $entity, 'id' => $id, 'action' => $verb, 'admin_id' => $actor->id, 'version' => $this->config->version(),
        ]);
    }

    /** @return array<string, mixed> JSON-safe attributes for the audit diff */
    public static function snapshot(Model $model): array
    {
        return json_decode((string) json_encode($model->attributesToArray()), true) ?? [];
    }
}
