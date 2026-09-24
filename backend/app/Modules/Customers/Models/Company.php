<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Database\Factories\Customers\CompanyFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legal entity of a PJ customer (DATABASE.md §3.4.1). `price_list_id` is
 * assigned explicitly (requires pricing.manage) and is not fillable.
 *
 * @property int $id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $cnpj
 * @property string|null $state_registration
 * @property bool $state_registration_exempt
 * @property int|null $price_list_id
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $table = 'companies';

    protected $fillable = ['legal_name', 'trade_name', 'cnpj', 'state_registration', 'state_registration_exempt'];

    protected function casts(): array
    {
        return [
            'state_registration_exempt' => 'boolean',
        ];
    }

    /** Stored without mask, upper-case (alphanumeric CNPJ). */
    protected function cnpj(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $value)));
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
