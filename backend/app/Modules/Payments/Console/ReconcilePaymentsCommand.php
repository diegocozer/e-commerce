<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\DefaultPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** payments:reconcile — every 5 min: syncFromGateway of pending payments aged 5 min … 24 h (lost webhooks). */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--limit=200}';

    protected $description = 'Consulta o gateway para pagamentos pendentes (webhooks perdidos).';

    public function handle(DefaultPaymentService $payments): int
    {
        $pending = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('external_id')
            ->whereBetween('created_at', [now()->subDay(), now()->subMinutes(5)])
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'provider', 'external_id']);

        $synced = 0;
        foreach ($pending as $payment) {
            try {
                $payments->syncFromGateway($payment->provider->value, (string) $payment->external_id);
                $synced++;
            } catch (Throwable $e) {
                Log::channel('payments')->warning('payment.reconcile_failed', ['payment_id' => $payment->id, 'exception' => $e::class]);
            }
        }

        $this->info("{$synced} pagamento(s) sincronizado(s).");

        return self::SUCCESS;
    }
}
