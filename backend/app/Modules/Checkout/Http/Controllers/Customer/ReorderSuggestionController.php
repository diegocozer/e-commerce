<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers\Customer;

use App\Modules\Checkout\Actions\ListReorderSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** GET /me/reorder-suggestions (API.md §3.D). */
final class ReorderSuggestionController
{
    public function index(Request $request, ListReorderSuggestions $action): JsonResponse
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:12']]);
        $response = new JsonResponse(['data' => $action->execute((int) Auth::guard('customer')->id(), (int) ($data['limit'] ?? 6))]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
