<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Store;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** GET /pages/{slug} — institutional pages from content.* settings (plain text). */
final class PageController
{
    /** @var array<string, array{0: SettingKey, 1: string}> */
    private const array PAGES = [
        'sobre' => [SettingKey::ContentAbout, 'Sobre nós'],
        'termos' => [SettingKey::ContentTerms, 'Termos de uso'],
        'privacidade' => [SettingKey::ContentPrivacy, 'Política de privacidade'],
        'trocas' => [SettingKey::ContentReturns, 'Trocas e devoluções'],
    ];

    public function show(string $slug, SettingsRepository $settings): JsonResponse
    {
        [$key, $title] = self::PAGES[$slug] ?? throw new NotFoundHttpException;
        $updatedAt = Setting::query()->where('key', $key->value)->value('updated_at');

        return new JsonResponse(['data' => [
            'slug' => $slug,
            'title' => $title,
            'body_text' => (string) $settings->string($key),
            'updated_at' => $updatedAt === null ? null : CarbonImmutable::parse($updatedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]);
    }
}
