<?php

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Cart\Providers\CartServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Checkout\Providers\CheckoutServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Pricing\Providers\PricingServiceProvider;
use App\Modules\Reports\Providers\ReportsServiceProvider;
use App\Modules\Seo\Providers\SeoServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Modules\Shipping\Providers\ShippingServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Modules, bottom-up in the dependency graph (config/modules.php).
    SettingsServiceProvider::class,
    IdentityServiceProvider::class,
    AuditServiceProvider::class,
    CustomersServiceProvider::class,
    InventoryServiceProvider::class,
    PricingServiceProvider::class,
    ShippingServiceProvider::class,
    PaymentsServiceProvider::class,
    CatalogServiceProvider::class,
    CartServiceProvider::class,
    OrdersServiceProvider::class,
    CheckoutServiceProvider::class,
    NotificationsServiceProvider::class,
    ReportsServiceProvider::class,
    SeoServiceProvider::class,
];
