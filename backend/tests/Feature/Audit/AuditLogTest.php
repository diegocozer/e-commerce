<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Enums\AdminPermission as P;
use App\Modules\Identity\Enums\AdminRole;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Identity\Support\ApiTestCase;

final class AuditLogTest extends ApiTestCase
{
    public function test_requires_audit_logs_view(): void
    {
        $this->actingAsAdmin([], AdminRole::Warehouse);
        $this->assertApiError($this->getJson('/api/v1/admin/audit-logs'), 403, 'forbidden');
        $this->assertApiError($this->getJson('/api/v1/admin/failed-jobs'), 403, 'forbidden');
    }

    public function test_lists_with_labels_and_filters(): void
    {
        $viewer = $this->actingAsAdmin([], AdminRole::Finance);
        $customer = Customer::factory()->create(['name' => 'Ana Souza']);
        $logger = app(AuditLogger::class);
        $logger->record(new AuditEntry(ActorRef::admin($viewer->id), 'customer.blocked', 'customer', $customer->id, ['is_active' => true], ['is_active' => false, 'cpf' => '52998224725', 'password' => 'x']));
        $this->travel(1)->days();
        $logger->record(new AuditEntry(ActorRef::system(), 'order.expired'));

        $this->getJson('/api/v1/admin/audit-logs')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'order.expired')
            ->assertJsonPath('data.0.actor', ['type' => 'system', 'id' => null, 'name' => null])
            ->assertJsonPath('data.1.actor.name', $viewer->name)
            ->assertJsonPath('data.1.auditable_label', 'Ana Souza')
            ->assertJsonPath('data.1.new_values.cpf', '***.982.247-**')
            ->assertJsonPath('data.1.new_values.password', '[REDACTED]')
            ->assertJsonStructure(['links', 'meta' => ['per_page', 'total']]);

        $this->getJson('/api/v1/admin/audit-logs?action=customer.')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/audit-logs?actor_type=admin&actor_id='.$viewer->id)->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/audit-logs?auditable_type=customer&auditable_id={$customer->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/audit-logs?sort=created_at')->assertJsonPath('data.0.action', 'customer.blocked');
        $today = now('America/Sao_Paulo')->toDateString();
        $this->getJson("/api/v1/admin/audit-logs?date_from={$today}&date_to={$today}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/audit-logs?sort=action')->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/admin/audit-logs?actor_type=robot')->assertJsonValidationErrors('actor_type');

        $id = AuditLog::query()->where('action', 'order.expired')->value('id');
        $this->getJson("/api/v1/admin/audit-logs/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/admin/audit-logs/999999')->assertNotFound();
    }

    public function test_request_metadata_is_recorded(): void
    {
        $this->actingAsAdmin([P::CustomersView, P::CustomersUpdate, P::AuditLogsView]);
        $customer = Customer::factory()->create();
        $requestId = '01J00000000000000000000000';
        $this->postJson("/api/v1/admin/customers/{$customer->id}/block", ['reason' => 'teste'], ['X-Request-Id' => $requestId, 'User-Agent' => 'PHPUnit'])->assertOk();

        $this->getJson("/api/v1/admin/audit-logs?request_id={$requestId}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'customer.blocked')
            ->assertJsonPath('data.0.user_agent', 'PHPUnit')
            ->assertJsonPath('data.0.ip', '127.0.0.1');
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(LogicException::class);
        $log->action = 'tampered';
        $log->save();
    }

    public function test_database_rejects_updates(): void
    {
        $log = AuditLog::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_no_write_routes_exist(): void
    {
        $this->actingAsAdmin([P::AuditLogsView]);
        $log = AuditLog::factory()->create();
        $this->deleteJson("/api/v1/admin/audit-logs/{$log->id}")->assertStatus(405);
        $this->patchJson("/api/v1/admin/audit-logs/{$log->id}", ['action' => 'x'])->assertStatus(405);
    }

    public function test_failed_jobs(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'a3f1c3b0-0000-4000-8000-000000000001', 'connection' => 'redis', 'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendMail', 'data' => ['secret' => 'x']]),
            'exception' => "RuntimeException: SMTP down\n#0 /app/foo.php(10)", 'failed_at' => now(),
        ]);
        $this->actingAsAdmin([P::AuditLogsView]);

        $this->getJson('/api/v1/admin/failed-jobs')->assertOk()
            ->assertJsonPath('data.0.job', 'App\\Jobs\\SendMail')
            ->assertJsonPath('data.0.queue', 'notifications')
            ->assertJsonPath('data.0.exception_summary', 'RuntimeException: SMTP down')
            ->assertJsonMissingPath('data.0.payload');
    }
}
