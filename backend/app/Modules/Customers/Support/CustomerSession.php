<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\DTOs\CartMergeReport;
use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Starts the customer session (fixation-safe) and merges the guest cart (API.md §3.C). */
final class CustomerSession
{
    public function __construct(private readonly GuestCartMerger $merger) {}

    public function start(Request $request, Customer $customer, bool $remember = false): ?CartMergeReport
    {
        Auth::guard('customer')->login($customer, $remember);
        $request->session()->regenerate();

        $token = $request->header('X-Cart-Token');
        $token = is_string($token) && Str::isUuid($token) ? strtolower($token) : null;

        $report = $token === null ? null : $this->merger->merge($token, $customer->id);
        event(new CustomerAuthenticated($customer->id, $token));

        return $report;
    }

    public static function end(Request $request): void
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
