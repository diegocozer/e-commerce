<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SlugRules
{
    public const string REGEX = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @return list<mixed> */
    public static function rules(string $table, int $max, ?int $ignoreId = null): array
    {
        return [
            'string', 'max:'.$max, 'regex:'.self::REGEX,
            Rule::notIn(config('catalog.reserved_slugs', [])),
            Rule::unique($table, 'slug')->whereNull('deleted_at')->ignore($ignoreId),
        ];
    }

    /** Unique slug derived from a name (suffix -2, -3… when taken, reserved words avoided). */
    public static function generate(string $table, string $name, ?int $ignoreId = null, int $max = 140): string
    {
        $base = Str::limit(Str::slug($name), $max - 4, '') ?: 'item';
        $base = trim($base, '-');
        $slug = $base;
        $i = 2;
        while (in_array($slug, config('catalog.reserved_slugs', []), true)
            || DB::table($table)->where('slug', $slug)->whereNull('deleted_at')->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
