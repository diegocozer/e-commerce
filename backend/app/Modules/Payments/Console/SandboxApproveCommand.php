<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\SandboxSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** payments:sandbox-approve {order_number} — staging helper (never in production). */
final class SandboxApproveCommand extends Command
{
    protected $signature = 'payments:sandbox-approve {order_number} {--fail} {--amount=}';

    protected $description = 'Simula aprovação (ou falha) do PIX sandbox enviando webhook assinado.';

    public function handle(SandboxSimulator $simulator): int
    {
        if (app()->isProduction() || config('payments.driver') !== 'sandbox') {
            $this->error('Disponível apenas com o driver sandbox fora de produção.');

            return self::FAILURE;
        }

        $orderId = DB::table('orders')->where('number', (string) $this->argument('order_number'))->value('id');
        $payment = $orderId === null ? null : $simulator->pendingPayment((int) $orderId);
        if ($payment === null) {
            $this->error('Pedido sem pagamento pendente.');

            return self::FAILURE;
        }

        $amount = $this->option('amount');
        $event = $simulator->simulate($payment, $this->option('fail') ? PaymentStatus::Failed : PaymentStatus::Approved, is_numeric($amount) ? (int) $amount : null);
        $this->info("Webhook {$event} enviado.");

        return self::SUCCESS;
    }
}
