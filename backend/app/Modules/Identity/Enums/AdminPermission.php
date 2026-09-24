<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Admin permissions (guard `admin`), canonical list of API.md §6.1 (ADR-028):
 * BUSINESS_RULES §4.14 matrix + ADR-023 additions + coupons.manage,
 * orders.pickup, reports.sales, reports.inventory. Created only by the seeder.
 */
enum AdminPermission: string
{
    case DashboardView = 'dashboard.view';
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case PricesManage = 'prices.manage';
    case PricingManage = 'pricing.manage';
    case PromotionsManage = 'promotions.manage';
    case CouponsManage = 'coupons.manage';
    case InventoryView = 'inventory.view';
    case InventoryMove = 'inventory.move';
    case InventoryAdjust = 'inventory.adjust';
    case OrdersView = 'orders.view';
    case OrdersFulfill = 'orders.fulfill';
    case OrdersPickup = 'orders.pickup';
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
    case ReportsSales = 'reports.sales';
    case ReportsInventory = 'reports.inventory';
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

    /** Group shown in the panel (API.md §2.12 Permission.group). */
    public function group(): string
    {
        return match ($this) {
            self::DashboardView => 'dashboard',
            self::ProductsView, self::ProductsManage => 'products',
            self::PricesManage, self::PricingManage, self::PromotionsManage, self::CouponsManage => 'pricing',
            self::InventoryView, self::InventoryMove, self::InventoryAdjust => 'inventory',
            self::OrdersView, self::OrdersFulfill, self::OrdersPickup, self::OrdersCancelUnpaid,
            self::OrdersCancelPaid, self::OrdersNotes => 'orders',
            self::PaymentsView, self::PaymentsReconcile => 'payments',
            self::CustomersView, self::CustomersViewSensitive, self::CustomersUpdate, self::CustomersManage => 'customers',
            self::ShippingManage => 'shipping',
            self::ReportsView, self::ReportsSales, self::ReportsInventory, self::ReportsExport => 'reports',
            self::AdminUsersManage => 'admin',
            self::SettingsManage => 'settings',
            self::AuditLogsView => 'audit',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Ver painel inicial',
            self::ProductsView => 'Ver produtos',
            self::ProductsManage => 'Gerenciar produtos, categorias e marcas',
            self::PricesManage => 'Gerenciar preços base e faixas',
            self::PricingManage => 'Gerenciar tabelas de preço e preços por cliente',
            self::PromotionsManage => 'Gerenciar promoções e cupons',
            self::CouponsManage => 'Gerenciar cupons',
            self::InventoryView => 'Ver estoque',
            self::InventoryMove => 'Registrar entrada de estoque',
            self::InventoryAdjust => 'Ajustar estoque',
            self::OrdersView => 'Ver pedidos',
            self::OrdersFulfill => 'Separar, enviar e entregar pedidos',
            self::OrdersPickup => 'Registrar retirada de pedidos',
            self::OrdersCancelUnpaid => 'Cancelar pedidos não pagos',
            self::OrdersCancelPaid => 'Cancelar pedidos pagos (estorno)',
            self::OrdersNotes => 'Notas internas de pedidos',
            self::PaymentsView => 'Ver pagamentos',
            self::PaymentsReconcile => 'Reconsultar pagamentos no gateway',
            self::CustomersView => 'Ver clientes',
            self::CustomersViewSensitive => 'Ver CPF/CNPJ completos',
            self::CustomersUpdate => 'Editar clientes',
            self::CustomersManage => 'Ações excepcionais em clientes (LGPD, documentos)',
            self::ShippingManage => 'Gerenciar frete',
            self::ReportsView => 'Ver todos os relatórios',
            self::ReportsSales => 'Ver relatórios de vendas',
            self::ReportsInventory => 'Ver relatórios de estoque',
            self::ReportsExport => 'Exportar relatórios (CSV)',
            self::AdminUsersManage => 'Gerenciar usuários e papéis',
            self::SettingsManage => 'Gerenciar configurações',
            self::AuditLogsView => 'Ver auditoria',
        };
    }
}
