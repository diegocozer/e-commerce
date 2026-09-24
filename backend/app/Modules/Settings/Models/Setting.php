<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use Database\Factories\Settings\SettingFactory;
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
            'value' => 'json',
            'is_public' => 'boolean',
        ];
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }
}
