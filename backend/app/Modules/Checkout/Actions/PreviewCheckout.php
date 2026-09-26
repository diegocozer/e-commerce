<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\DTOs\CheckoutData;
use App\Modules\Checkout\Services\CheckoutCalculator;

/**
 * POST /checkout/preview: recalculated CheckoutSummary, no business side effects
 * (only a new shipping quote may be persisted on divergence — SHIPPING §7).
 * Always 200 for a valid body; problems go to `blocking[]`.
 */
final class PreviewCheckout
{
    public function __construct(private readonly CheckoutCalculator $calculator) {}

    /** @return array<string, mixed> CheckoutSummary */
    public function execute(CheckoutData $data): array
    {
        return $this->calculator->compute($data, strict: false)->summary;
    }
}
