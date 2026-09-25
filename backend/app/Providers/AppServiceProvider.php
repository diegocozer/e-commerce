<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cart\Models\Cart;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\Promotion;
use App\Modules\Settings\Models\Setting;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Shared\Http\RateLimiting\RateLimiters;
use App\Shared\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Morph map (DB-04): polymorphic `*_type` columns store these aliases,
     * never class names (model_has_roles, notifications, audit_logs,
     * inventory_movements).
     */
    public const array MORPH_MAP = [
        'admin_user' => AdminUser::class,
        'customer' => Customer::class,
        'order' => Order::class,
        'payment' => Payment::class,
        'product' => Product::class,
        'product_variant' => ProductVariant::class,
        'category' => Category::class,
        'brand' => Brand::class,
        'coupon' => Coupon::class,
        'promotion' => Promotion::class,
        'shipping_method' => ShippingMethod::class,
        'shipping_rule' => ShippingRule::class,
        'inventory' => Inventory::class,
        'setting' => Setting::class,
        'price_list' => PriceList::class,
        // Not in DATABASE.md §1.8 but needed as auditable/reference types.
        'cart' => Cart::class,
        'audit_log' => AuditLog::class,
    ];

    public function register(): void
    {
        $this->app->singleton(HtmlSanitizer::class, fn (): HtmlSanitizer => new HtmlSanitizer(
            storage_path('framework/cache/htmlpurifier'),
        ));
    }

    public function boot(): void
    {
        Relation::enforceMorphMap(self::MORPH_MAP);

        // Lazy loading, silently discarded attributes and missing attributes
        // throw outside production (ARCHITECTURE.md §3.3).
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiters::register();

        // Customer password policy (SECURITY.md §3.2); the admin policy is
        // stricter and applied explicitly by the Identity module.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(8)->max(72)->letters()->numbers()->uncompromised()
            : Password::min(8)->max(72)->letters()->numbers());
    }
}
