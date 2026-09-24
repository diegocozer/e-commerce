<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Admin permissions (guard `admin`), `<resource>.<action>` — DATABASE.md §7.2 =
 * BUSINESS_RULES.md §4.14 matrix + pricing.manage, inventory.view,
 * customers.update, reports.export (ADR-023). Created only by the seeder.
 */
enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case PricesManage = 'prices.manage';
    case PricingManage = 'pricing.manage';
    case PromotionsManage = 'promotions.manage';
    case InventoryView = 'inventory.view';
    case InventoryMove = 'inventory.move';
    case InventoryAdjust = 'inventory.adjust';
    case OrdersView = 'orders.view';
    case OrdersFulfill = 'orders.fulfill';
    case OrdersCancelUnpaid = 'orders.cancel_unpaid';
    case OrdersCancelPaid = 'orders.cancel_paid';
    case OrdersNotes = 'orders.notes';
    case PaymentsView = 'payments.view';
    case PaymentsReconcile = 'payments.reconcile';
    case CustomersView = 'customers.view';
    case CustomersViewSensitive = 'customers.view_sensitive';
    case CustomersUpdate = 'customers.update';
    case CustomersManage = 'customers.manage';
    case ShippingManage = 'shipping.manage';
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case AdminUsersManage = 'admin_users.manage';
    case SettingsManage = 'settings.manage';
    case AuditLogsView = 'audit_logs.view';

    public const string GUARD = 'admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
