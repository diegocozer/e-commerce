<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

use App\Modules\Identity\Enums\AdminPermission as P;

/**
 * Seeded admin roles (ADR-023 / ADR-027, assignment of API.md §6.2).
 * `super-admin` gets everything through Gate::before; `manager` gets every
 * permission except admin_users.manage (only super-admin manages users and
 * roles). Custom roles may be created through the panel; these are "system" roles.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super-admin';
    case Manager = 'manager';
    case Seller = 'seller';
    case Warehouse = 'warehouse';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Manager => 'Gerente',
            self::Seller => 'Vendedor',
            self::Warehouse => 'Estoque/Expedição',
            self::Finance => 'Financeiro',
        };
    }

    /** @return list<AdminPermission> permissions assigned explicitly by the seeder */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],
            self::Manager => array_values(array_filter(P::cases(), static fn (P $p): bool => $p !== P::AdminUsersManage)),
            self::Seller => [
                P::DashboardView, P::ProductsView, P::CouponsManage, P::InventoryView, P::OrdersView,
                P::OrdersPickup, P::OrdersCancelUnpaid, P::OrdersNotes, P::PaymentsView, P::CustomersView,
                P::CustomersViewSensitive, P::CustomersUpdate, P::ReportsSales,
            ],
            self::Warehouse => [
                P::DashboardView, P::ProductsView, P::InventoryView, P::InventoryMove, P::InventoryAdjust,
                P::OrdersView, P::OrdersFulfill, P::OrdersPickup, P::OrdersNotes, P::ReportsInventory,
            ],
            self::Finance => [
                P::DashboardView, P::ProductsView, P::InventoryView, P::OrdersView, P::OrdersCancelUnpaid,
                P::OrdersCancelPaid, P::OrdersNotes, P::PaymentsView, P::PaymentsReconcile, P::CustomersView,
                P::CustomersViewSensitive, P::ReportsView, P::ReportsExport, P::AuditLogsView,
            ],
        };
    }
}
