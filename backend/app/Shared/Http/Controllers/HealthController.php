<?php

declare(strict_types=1);

namespace App\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health checks (ARCHITECTURE.md §10.5). No versions, hostnames or exception
 * messages are ever exposed.
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function show(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn (): bool => DB::selectOne('select 1 as ok')?->ok === 1),
            'cache' => $this->check(static function (): bool {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $ok;
            }),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return new JsonResponse(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503);
    }

    /** @param  callable(): bool  $probe */
    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
