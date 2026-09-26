<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Customer;

use App\Modules\Orders\Support\OrderPresenter as P;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** OrderPayment (API.md §2.8). @mixin Payment */
class OrderPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return (array) P::payment($this->resource);
    }
}
