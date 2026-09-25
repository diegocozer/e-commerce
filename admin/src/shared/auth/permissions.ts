import type { Permission, PermissionName } from '@/shared/api/types';

/** Catálogo da API §6.1 (rótulos pt-BR). Fonte da verdade em runtime: GET /admin/permissions. */
export const PERMISSION_CATALOG: Permission[] = [
  { name: 'dashboard.view', label: 'Ver dashboard', group: 'dashboard' },
  { name: 'products.view', label: 'Ver catálogo', group: 'products' },
  { name: 'products.manage', label: 'Gerenciar catálogo', group: 'products' },
  { name: 'prices.manage', label: 'Preço base, promo e custo', group: 'pricing' },
  { name: 'pricing.manage', label: 'Tabelas e preços por cliente', group: 'pricing' },
  { name: 'promotions.manage', label: 'Promoções e cupons', group: 'pricing' },
  { name: 'coupons.manage', label: 'Somente cupons', group: 'pricing' },
  { name: 'inventory.view', label: 'Ver estoque', group: 'inventory' },
  { name: 'inventory.move', label: 'Entrada de estoque', group: 'inventory' },
  { name: 'inventory.adjust', label: 'Ajuste de estoque', group: 'inventory' },
  { name: 'orders.view', label: 'Ver pedidos', group: 'orders' },
  { name: 'orders.fulfill', label: 'Separar/enviar/entregar', group: 'orders' },
  { name: 'orders.pickup', label: 'Registrar retirada', group: 'orders' },
  { name: 'orders.cancel_unpaid', label: 'Cancelar não pagos', group: 'orders' },
  { name: 'orders.cancel_paid', label: 'Cancelar pagos (estorno)', group: 'orders' },
  { name: 'orders.notes', label: 'Notas internas', group: 'orders' },
  { name: 'payments.view', label: 'Ver transações', group: 'payments' },
  { name: 'payments.reconcile', label: 'Reconsultar gateway', group: 'payments' },
  { name: 'customers.view', label: 'Ver clientes', group: 'customers' },
  { name: 'customers.view_sensitive', label: 'Revelar documentos', group: 'customers' },
  { name: 'customers.update', label: 'Editar/bloquear clientes', group: 'customers' },
  { name: 'customers.manage', label: 'Corrigir documento / anonimizar', group: 'customers' },
  { name: 'shipping.manage', label: 'Gerenciar frete', group: 'shipping' },
  { name: 'reports.view', label: 'Todos os relatórios', group: 'reports' },
  { name: 'reports.sales', label: 'Relatórios de vendas', group: 'reports' },
  { name: 'reports.inventory', label: 'Relatórios de estoque', group: 'reports' },
  { name: 'reports.export', label: 'Exportar CSV', group: 'reports' },
  { name: 'admin_users.manage', label: 'Usuários e papéis', group: 'admin' },
  { name: 'settings.manage', label: 'Configurações', group: 'settings' },
  { name: 'audit_logs.view', label: 'Auditoria', group: 'audit' },
];

export const ALL_PERMISSIONS: PermissionName[] = PERMISSION_CATALOG.map((p) => p.name);

export const PERMISSION_GROUP_LABEL: Record<Permission['group'], string> = {
  dashboard: 'Dashboard',
  products: 'Produtos',
  pricing: 'Preços & promoções',
  inventory: 'Estoque',
  orders: 'Pedidos',
  payments: 'Pagamentos',
  customers: 'Clientes',
  shipping: 'Frete',
  reports: 'Relatórios',
  admin: 'Usuários',
  settings: 'Configurações',
  audit: 'Auditoria',
};
