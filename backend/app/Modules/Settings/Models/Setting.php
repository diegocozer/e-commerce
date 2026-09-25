<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Database\Factories\Settings\SettingFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value configuration editable in the panel (DATABASE.md §3.10.3).
 * Read through App\Modules\Settings\Contracts\SettingsRepository (cached).
 * `updated_by` is set explicitly by the Settings action (not fillable).
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property string $group
 * @property bool $is_public
 * @property string|null $description
 * @property int|null $updated_by
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'group', 'is_public', 'description'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * Any JSON value, including null (stored as the JSON literal `null`, since
     * the column is NOT NULL). Not a plain `json` cast because it would write SQL NULL.
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): mixed => $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }
}
