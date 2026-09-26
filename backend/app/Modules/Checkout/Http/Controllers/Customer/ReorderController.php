<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers\Customer;

use App\Modules\Checkout\Actions\ReorderOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** POST /me/orders/{uuid}/reorder (API.md §3.D). */
final class ReorderController
{
    public function __invoke(string $uuid, ReorderOrder $action): JsonResponse
    {
        $response = new JsonResponse(['data' => $action->execute((int) Auth::guard('customer')->id(), $uuid)]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
