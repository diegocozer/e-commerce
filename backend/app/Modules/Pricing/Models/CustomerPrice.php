<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use Database\Factories\Pricing\CustomerPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Negotiated price for a customer XOR a company, with validity window
 * (DATABASE.md §3.2.3). `created_by` is set explicitly by the Pricing action.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $company_id
 * @property int $variant_id
 * @property int $price_cents
 */
class CustomerPrice extends Model
{
    /** @use HasFactory<CustomerPriceFactory> */
    use HasFactory;

    protected $table = 'customer_prices';

    protected $fillable = ['customer_id', 'company_id', 'variant_id', 'price_cents', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): CustomerPriceFactory
    {
        return CustomerPriceFactory::new();
    }
}
