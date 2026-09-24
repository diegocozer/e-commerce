<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\CustomerType;
use Database\Factories\Customers\CustomerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Storefront customer (guard `customer`, DATABASE.md §3.4.2). Public id is
 * `uuid`; the admin panel addresses customers by `id`.
 * Not fillable on purpose (set explicitly by Customers actions): type,
 * company_id, price_list_id, email_verified_at, is_active, terms_*,
 * last_login_at, anonymized_at.
 *
 * @property int $id
 * @property string $uuid
 * @property CustomerType $type
 * @property string $name
 * @property string $email
 * @property string|null $cpf
 * @property string|null $phone
 * @property int|null $company_id
 * @property int|null $price_list_id
 * @property bool $is_active
 */
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'customers';

    protected $fillable = ['name', 'email', 'password', 'cpf', 'phone', 'marketing_opt_in'];

    protected $hidden = ['password', 'remember_token'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'password' => 'hashed',
            'email_verified_at' => 'immutable_datetime',
            'marketing_opt_in' => 'boolean',
            'marketing_opt_in_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'anonymized_at' => 'immutable_datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtolower(trim($value)));
    }

    protected function cpf(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null ? null : (string) preg_replace('/\D/', '', $value));
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null ? null : (string) preg_replace('/\D/', '', $value));
    }

    public function isCompany(): bool
    {
        return $this->type === CustomerType::Company;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasOne<CustomerAddress, $this> */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
