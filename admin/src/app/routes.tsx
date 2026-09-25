import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { Outlet, type RouteObject } from 'react-router-dom';
import type { PermissionCheck } from '@/shared/auth';
import { AdminLayout } from './layouts/AdminLayout';
import { REPORT_PERMS } from './navigation';
import { NotFoundPage } from './pages/NotFoundPage';
import { RouteError } from './pages/RouteError';
import { RequireAuth } from './RequireAuth';
import { RequirePermission } from './RequirePermission';
import { SessionHandlers } from './SessionHandlers';

type Page = LazyExoticComponent<ComponentType>;
const p = (load: () => Promise<{ default: ComponentType }>): Page => lazy(load);

// Code-split por rota (ARCHITECTURE §8.8).
const Pages = {
  Login: p(() => import('@/features/auth/pages/LoginPage')),
  Forgot: p(() => import('@/features/auth/pages/ForgotPasswordPage')),
  Reset: p(() => import('@/features/auth/pages/ResetPasswordPage')),
  ChangePassword: p(() => import('@/features/auth/pages/ChangePasswordPage')),
  Dashboard: p(() => import('@/features/dashboard/pages/DashboardPage')),
  Products: p(() => import('@/features/products/pages/ProductsListPage')),
  ProductForm: p(() => import('@/features/products/pages/ProductFormPage')),
  Categories: p(() => import('@/features/categories/pages/CategoriesPage')),
  Brands: p(() => import('@/features/brands/pages/BrandsPage')),
  Inventory: p(() => import('@/features/inventory/pages/InventoryPage')),
  Orders: p(() => import('@/features/orders/pages/OrdersListPage')),
  OrderDetail: p(() => import('@/features/orders/pages/OrderDetailPage')),
  Customers: p(() => import('@/features/customers/pages/CustomersListPage')),
  CustomerDetail: p(() => import('@/features/customers/pages/CustomerDetailPage')),
  Companies: p(() => import('@/features/customers/pages/CompaniesListPage')),
  CompanyDetail: p(() => import('@/features/customers/pages/CompanyDetailPage')),
  PriceLists: p(() => import('@/features/pricing/pages/PriceListsPage')),
  PriceListDetail: p(() => import('@/features/pricing/pages/PriceListDetailPage')),
  CustomerPrices: p(() => import('@/features/pricing/pages/CustomerPricesPage')),
  Promotions: p(() => import('@/features/promotions/pages/PromotionsPage')),
  Coupons: p(() => import('@/features/promotions/pages/CouponsPage')),
  Carriers: p(() => import('@/features/shipping/pages/CarriersPage')),
  Methods: p(() => import('@/features/shipping/pages/MethodsPage')),
  Zones: p(() => import('@/features/shipping/pages/ZonesPage')),
  ZoneForm: p(() => import('@/features/shipping/pages/ZoneFormPage')),
  Rules: p(() => import('@/features/shipping/pages/RulesPage')),
  Simulator: p(() => import('@/features/shipping/pages/SimulatorPage')),
  Users: p(() => import('@/features/users/pages/UsersPage')),
  Roles: p(() => import('@/features/users/pages/RolesPage')),
  Settings: p(() => import('@/features/settings/pages/SettingsPage')),
  Audit: p(() => import('@/features/audit/pages/AuditLogsPage')),
  Reports: p(() => import('@/features/reports/pages/ReportsPage')),
};

function guarded(path: string, perm: PermissionCheck | null, Page: Page): RouteObject {
  return { path, element: perm ? <RequirePermission perm={perm}><Page /></RequirePermission> : <Page /> };
}

function Root() {
  return (
    <>
      <SessionHandlers />
      <Outlet />
    </>
  );
}

export const routes: RouteObject[] = [
  {
    element: <Root />,
    errorElement: <RouteError />,
    children: [
      { path: 'entrar', element: <Pages.Login /> },
      { path: 'recuperar-senha', element: <Pages.Forgot /> },
      { path: 'redefinir-senha', element: <Pages.Reset /> },
      {
        element: (
          <RequireAuth>
            <AdminLayout />
          </RequireAuth>
        ),
        errorElement: <RouteError />,
        children: [
          guarded('/', 'dashboard.view', Pages.Dashboard),
          guarded('conta/senha', null, Pages.ChangePassword),
          guarded('produtos', 'products.view', Pages.Products),
          guarded('produtos/novo', 'products.manage', Pages.ProductForm),
          guarded('produtos/:id', 'products.view', Pages.ProductForm),
          guarded('categorias', 'products.view', Pages.Categories),
          guarded('marcas', 'products.view', Pages.Brands),
          guarded('estoque', 'inventory.view', Pages.Inventory),
          guarded('pedidos', 'orders.view', Pages.Orders),
          guarded('pedidos/:id', 'orders.view', Pages.OrderDetail),
          guarded('clientes', 'customers.view', Pages.Customers),
          guarded('clientes/:id', 'customers.view', Pages.CustomerDetail),
          guarded('empresas', 'customers.view', Pages.Companies),
          guarded('empresas/:id', 'customers.view', Pages.CompanyDetail),
          guarded('precos/tabelas', 'products.view', Pages.PriceLists),
          guarded('precos/tabelas/:id', 'products.view', Pages.PriceListDetail),
          guarded('precos/clientes', 'customers.view', Pages.CustomerPrices),
          guarded('promocoes', 'products.view', Pages.Promotions),
          guarded('cupons', ['coupons.manage', 'promotions.manage'], Pages.Coupons),
          guarded('frete/transportadoras', 'shipping.manage', Pages.Carriers),
          guarded('frete/metodos', 'shipping.manage', Pages.Methods),
          guarded('frete/zonas', 'shipping.manage', Pages.Zones),
          guarded('frete/zonas/nova', 'shipping.manage', Pages.ZoneForm),
          guarded('frete/zonas/:id', 'shipping.manage', Pages.ZoneForm),
          guarded('frete/regras', 'shipping.manage', Pages.Rules),
          guarded('frete/simulador', 'shipping.manage', Pages.Simulator),
          guarded('usuarios', 'admin_users.manage', Pages.Users),
          guarded('papeis', 'admin_users.manage', Pages.Roles),
          guarded('configuracoes', 'settings.manage', Pages.Settings),
          guarded('auditoria', 'audit_logs.view', Pages.Audit),
          guarded('relatorios', REPORT_PERMS, Pages.Reports),
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
];
