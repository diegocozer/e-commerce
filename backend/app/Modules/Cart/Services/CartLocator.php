<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Exceptions\CartNotFound;
use App\Modules\Cart\Models\Cart;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cart identification (API.md §3.B): logged-in customer → the customer's active
 * cart (X-Cart-Token ignored); guest → X-Cart-Token. Unknown, expired, converted
 * or customer-owned tokens → 404 cart_not_found (SEC-IDOR-05).
 */
final class CartLocator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Existing cart or null (reads never create a cart). */
    public function find(?int $customerId, ?string $token, bool $lock = false): ?Cart
    {
        if ($customerId !== null) {
            return $this->activeForCustomer($customerId, $lock);
        }
        if ($token === null || $token === '') {
            return null;
        }

        return $this->guestByToken($token, $lock) ?? throw new CartNotFound;
    }

    /** Existing cart or a new one (writes). Second value: whether it was created. */
    public function findOrCreate(?int $customerId, ?string $token): array
    {
        $cart = $this->find($customerId, $token, lock: true);
        if ($cart !== null) {
            return [$cart, false];
        }

        $cart = new Cart;
        $cart->expires_at = $this->guestExpiry();
        if ($customerId !== null) {
            $cart->customer_id = $customerId;
        }
        try {
            DB::transaction(static fn () => $cart->save());
        } catch (UniqueConstraintViolationException) {
            // concurrent creation of the customer's active cart
            return [$this->activeForCustomer((int) $customerId, true) ?? throw new CartNotFound, false];
        }

        return [$cart->refresh(), true];
    }

    public function activeForCustomer(int $customerId, bool $lock = false): ?Cart
    {
        $query = Cart::query()->where('customer_id', $customerId)->whereNull('converted_at');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function guestByToken(string $token, bool $lock = false): ?Cart
    {
        if (! Str::isUuid($token)) {
            return null;
        }
        $query = Cart::query()
            ->where('token', strtolower($token))
            ->whereNull('customer_id')
            ->whereNull('converted_at')
            ->where('expires_at', '>', now());

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** Renews the guest expiry on every write (DATABASE.md §3.5.1). */
    public function touch(Cart $cart): void
    {
        $cart->expires_at = $this->guestExpiry();
        $cart->updated_at = now();
        $cart->save();
    }

    private function guestExpiry(): \DateTimeInterface
    {
        $days = (int) ($this->settings->get(SettingKey::CartGuestTtlDays) ?? 30);

        return now()->addDays($days > 0 ? $days : 30);
    }
}
