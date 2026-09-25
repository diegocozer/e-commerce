<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Store;

use App\Modules\Cart\Actions\AcknowledgeCartPrices;
use App\Modules\Cart\Actions\AddCartItem;
use App\Modules\Cart\Actions\ApplyCartCoupon;
use App\Modules\Cart\Actions\QuoteCartShipping;
use App\Modules\Cart\Actions\RemoveCartItem;
use App\Modules\Cart\Actions\UpdateCartItem;
use App\Modules\Cart\Http\Requests\Store\AddCartItemRequest;
use App\Modules\Cart\Http\Requests\Store\ApplyCouponRequest;
use App\Modules\Cart\Http\Requests\Store\CartShippingQuoteRequest;
use App\Modules\Cart\Http\Requests\Store\ShowCartRequest;
use App\Modules\Cart\Http\Requests\Store\UpdateCartItemRequest;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Cart\Services\CartView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** /api/v1/cart* (API.md §3.B). Thin: identifies the cart owner and delegates. */
final class CartController
{
    public function __construct(private readonly CartView $view) {}

    public function show(ShowCartRequest $request, CartLocator $carts): JsonResponse
    {
        $customerId = $this->customerId();
        $cart = $carts->find($customerId, $this->token($request));

        return $this->respond($cart, $customerId, 200, false,
            $request->validated('shipping_quote_id'), $request->validated('shipping_option_id'));
    }

    public function addItem(AddCartItemRequest $request, AddCartItem $action): JsonResponse
    {
        $customerId = $this->customerId();
        $result = $action->execute($customerId, $this->token($request), $request->validated());

        return $this->respond($result['cart'], $customerId, $result['line_created'] ? 201 : 200, $result['cart_created']);
    }

    public function updateItem(UpdateCartItemRequest $request, int $item, UpdateCartItem $action): JsonResponse
    {
        $customerId = $this->customerId();

        return $this->respond($action->execute($customerId, $this->token($request), $item, $request->validated()), $customerId);
    }

    public function removeItem(Request $request, int $item, RemoveCartItem $action): JsonResponse
    {
        $customerId = $this->customerId();

        return $this->respond($action->execute($customerId, $this->token($request), $item), $customerId);
    }

    public function clear(Request $request, RemoveCartItem $action): JsonResponse
    {
        $customerId = $this->customerId();

        return $this->respond($action->clear($customerId, $this->token($request)), $customerId);
    }

    public function applyCoupon(ApplyCouponRequest $request, ApplyCartCoupon $action): JsonResponse
    {
        $customerId = $this->customerId();
        $result = $action->apply($customerId, $this->token($request), (string) $request->validated('code'));

        return $this->respond($result['cart'], $customerId, 200, $result['cart_created']);
    }

    public function removeCoupon(Request $request, ApplyCartCoupon $action): JsonResponse
    {
        $customerId = $this->customerId();

        return $this->respond($action->remove($customerId, $this->token($request)), $customerId);
    }

    public function shippingQuote(CartShippingQuoteRequest $request, QuoteCartShipping $action): JsonResponse
    {
        $quote = $action->execute($this->customerId(), $this->token($request),
            $request->validated('postal_code'), $request->validated('address_uuid'));

        return $this->noStore(new JsonResponse(['data' => $quote->toArray()]));
    }

    public function acknowledgePrices(Request $request, AcknowledgeCartPrices $action): JsonResponse
    {
        $customerId = $this->customerId();

        return $this->respond($action->execute($customerId, $this->token($request)), $customerId);
    }

    private function respond(?Cart $cart, ?int $customerId, int $status = 200, bool $created = false, ?string $quoteId = null, ?string $optionId = null): JsonResponse
    {
        $data = $cart === null ? $this->view->empty($customerId) : $this->view->build($cart, $customerId, $quoteId, $optionId);
        $response = new JsonResponse(['data' => $data], $status);
        if ($created && $cart !== null && $customerId === null) {
            $response->headers->set('X-Cart-Token', (string) $cart->token);
        }

        return $this->noStore($response);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function customerId(): ?int
    {
        $id = Auth::guard('customer')->id();

        return $id !== null ? (int) $id : null;
    }

    /** Ignored for logged-in customers (API.md §1.3). */
    private function token(Request $request): ?string
    {
        if ($this->customerId() !== null) {
            return null;
        }
        $token = $request->header('X-Cart-Token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
