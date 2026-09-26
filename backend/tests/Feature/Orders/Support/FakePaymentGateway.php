<?php

declare(strict_types=1);

namespace Tests\Feature\Orders\Support;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\GatewayRefund;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** In-memory controllable gateway (ARCHITECTURE.md §7.1): records calls and transaction levels. */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var array<string, array{status: PaymentStatus, amount: int, reference: ?string, currency: string}> */
    public array $payments = [];

    /** @var list<array{op: string, id: string, tx_level: int}> */
    public array $calls = [];

    public bool $down = false;

    public PaymentRefundStatus $refundResult = PaymentRefundStatus::Succeeded;

    public function createPayment(PaymentRequest $request, string $idempotencyKey): GatewayPayment
    {
        $this->calls[] = ['op' => 'create', 'id' => $idempotencyKey, 'tx_level' => DB::transactionLevel()];
        if ($this->down) {
            throw new PaymentGatewayUnavailable;
        }
        $id = 'fake_'.Str::lower((string) Str::ulid());
        $this->payments[$id] = ['status' => PaymentStatus::Pending, 'amount' => $request->amount->cents(), 'reference' => $request->externalReference, 'currency' => 'BRL'];

        return $this->getPaymentState($id, true);
    }

    public function getPayment(string $externalId): GatewayPayment
    {
        $this->calls[] = ['op' => 'get', 'id' => $externalId, 'tx_level' => DB::transactionLevel()];
        if ($this->down) {
            throw new PaymentGatewayUnavailable;
        }

        return $this->getPaymentState($externalId);
    }

    public function refund(string $externalId, ?Money $amount, string $idempotencyKey): GatewayRefund
    {
        $this->calls[] = ['op' => 'refund', 'id' => $externalId, 'tx_level' => DB::transactionLevel()];
        if ($this->down) {
            throw new PaymentGatewayUnavailable;
        }

        return new GatewayRefund('ref_'.substr(md5($idempotencyKey), 0, 10), $this->refundResult, $amount ?? Money::ofCents($this->payments[$externalId]['amount']));
    }

    public function supports(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::Pix;
    }

    public function set(string $externalId, PaymentStatus $status, ?int $amount = null, string $currency = 'BRL'): void
    {
        $this->payments[$externalId]['status'] = $status;
        $this->payments[$externalId]['currency'] = $currency;
        if ($amount !== null) {
            $this->payments[$externalId]['amount'] = $amount;
        }
    }

    public function count(string $op): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c['op'] === $op));
    }

    private function getPaymentState(string $id, bool $withPix = false): GatewayPayment
    {
        $p = $this->payments[$id];

        return new GatewayPayment($id, $p['status'], Money::ofCents($p['amount']), $p['currency'], $p['reference'],
            pixCopyPaste: '00020126FAKE'.$id, pixQrCodeBase64: $withPix ? 'iVBORw0KGgo=' : null);
    }
}
