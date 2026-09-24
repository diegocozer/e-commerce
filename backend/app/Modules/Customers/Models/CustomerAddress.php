<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Database\Factories\Customers\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer address (DATABASE.md §3.4.4). Route key `uuid` (customer routes).
 * `customer_id` and `is_default` are set explicitly by Customers actions.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property string $postal_code
 * @property string $state
 * @property string|null $city_ibge_code
 * @property bool $is_default
 */
class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'label', 'recipient_name', 'phone', 'postal_code', 'street', 'number', 'complement',
        'district', 'city', 'state', 'city_ibge_code', 'reference',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected function postalCode(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => (string) preg_replace('/\D/', '', $value));
    }

    protected function state(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => strtoupper(trim($value)));
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }
}
