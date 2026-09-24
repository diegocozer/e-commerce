<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\IbgeCityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IBGE municipality reference (DATABASE.md §3.8.2a). Natural key, no timestamps.
 *
 * @property string $ibge_code
 * @property string $name
 * @property string $state
 */
class IbgeCity extends Model
{
    /** @use HasFactory<IbgeCityFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'ibge_cities';

    protected $primaryKey = 'ibge_code';

    protected $keyType = 'string';

    protected $fillable = ['ibge_code', 'name', 'state'];

    protected static function newFactory(): IbgeCityFactory
    {
        return IbgeCityFactory::new();
    }
}
