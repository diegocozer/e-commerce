import AssessmentIcon from '@mui/icons-material/AssessmentOutlined';
import BadgeIcon from '@mui/icons-material/BadgeOutlined';
import BusinessIcon from '@mui/icons-material/BusinessOutlined';
import CalculateIcon from '@mui/icons-material/CalculateOutlined';
import CategoryIcon from '@mui/icons-material/CategoryOutlined';
import DashboardIcon from '@mui/icons-material/DashboardOutlined';
import GavelIcon from '@mui/icons-material/GavelOutlined';
import HistoryIcon from '@mui/icons-material/HistoryOutlined';
import Inventory2Icon from '@mui/icons-material/Inventory2Outlined';
import LabelIcon from '@mui/icons-material/LabelOutlined';
import LocalOfferIcon from '@mui/icons-material/LocalOfferOutlined';
import LocalShippingIcon from '@mui/icons-material/LocalShippingOutlined';
import MapIcon from '@mui/icons-material/MapOutlined';
import PeopleIcon from '@mui/icons-material/PeopleAltOutlined';
import PercentIcon from '@mui/icons-material/PercentOutlined';
import PriceChangeIcon from '@mui/icons-material/PriceChangeOutlined';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLongOutlined';
import RouteIcon from '@mui/icons-material/AltRouteOutlined';
import SellIcon from '@mui/icons-material/SellOutlined';
import SettingsIcon from '@mui/icons-material/SettingsOutlined';
import ShieldIcon from '@mui/icons-material/AdminPanelSettingsOutlined';
import StoreIcon from '@mui/icons-material/StorefrontOutlined';
import WarehouseIcon from '@mui/icons-material/WarehouseOutlined';
import type { ReactNode } from 'react';
import type { PermissionCheck } from '@/shared/auth';

export interface NavItem {
  label: string;
  to: string;
  perm: PermissionCheck;
  icon: ReactNode;
  /** Mostra contador de pedidos a separar. */
  badge?: 'to_pick';
}
export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const REPORT_PERMS = ['reports.view', 'reports.sales', 'reports.inventory'] as const;

/** Sidebar agrupada conforme UX §5.1; permissões conforme API §6.3. */
export const NAV: NavGroup[] = [
  { label: 'Visão geral', items: [{ label: 'Dashboard', to: '/', perm: 'dashboard.view', icon: <DashboardIcon /> }] },
  {
    label: 'Catálogo',
    items: [
      { label: 'Produtos', to: '/produtos', perm: 'products.view', icon: <StoreIcon /> },
      { label: 'Categorias', to: '/categorias', perm: 'products.view', icon: <CategoryIcon /> },
      { label: 'Marcas', to: '/marcas', perm: 'products.view', icon: <LabelIcon /> },
    ],
  },
  { label: 'Estoque', items: [{ label: 'Estoque', to: '/estoque', perm: 'inventory.view', icon: <WarehouseIcon /> }] },
  {
    label: 'Vendas',
    items: [
      { label: 'Pedidos', to: '/pedidos', perm: 'orders.view', icon: <ReceiptLongIcon />, badge: 'to_pick' },
      { label: 'Clientes', to: '/clientes', perm: 'customers.view', icon: <PeopleIcon /> },
      { label: 'Empresas', to: '/empresas', perm: 'customers.view', icon: <BusinessIcon /> },
    ],
  },
  {
    label: 'Preços & promoções',
    items: [
      { label: 'Tabelas de preço', to: '/precos/tabelas', perm: 'products.view', icon: <PriceChangeIcon /> },
      { label: 'Preços por cliente', to: '/precos/clientes', perm: 'customers.view', icon: <SellIcon /> },
      { label: 'Promoções', to: '/promocoes', perm: 'products.view', icon: <PercentIcon /> },
      { label: 'Cupons', to: '/cupons', perm: ['coupons.manage', 'promotions.manage'], icon: <LocalOfferIcon /> },
    ],
  },
  {
    label: 'Frete',
    items: [
      { label: 'Transportadoras', to: '/frete/transportadoras', perm: 'shipping.manage', icon: <LocalShippingIcon /> },
      { label: 'Métodos', to: '/frete/metodos', perm: 'shipping.manage', icon: <Inventory2Icon /> },
      { label: 'Zonas/Regiões', to: '/frete/zonas', perm: 'shipping.manage', icon: <MapIcon /> },
      { label: 'Regras', to: '/frete/regras', perm: 'shipping.manage', icon: <RouteIcon /> },
      { label: 'Simulador', to: '/frete/simulador', perm: 'shipping.manage', icon: <CalculateIcon /> },
    ],
  },
  {
    label: 'Sistema',
    items: [
      { label: 'Configurações', to: '/configuracoes', perm: 'settings.manage', icon: <SettingsIcon /> },
      { label: 'Usuários', to: '/usuarios', perm: 'admin_users.manage', icon: <BadgeIcon /> },
      { label: 'Papéis & permissões', to: '/papeis', perm: 'admin_users.manage', icon: <ShieldIcon /> },
      { label: 'Logs de auditoria', to: '/auditoria', perm: 'audit_logs.view', icon: <HistoryIcon /> },
      { label: 'Relatórios', to: '/relatorios', perm: REPORT_PERMS, icon: <AssessmentIcon /> },
    ],
  },
];

export const LEGAL_ICON = <GavelIcon />;

/** Encontra grupo/item para o caminho atual (prefixo mais longo). */
export function findNav(pathname: string): { group: NavGroup; item: NavItem } | null {
  let best: { group: NavGroup; item: NavItem } | null = null;
  for (const group of NAV) {
    for (const item of group.items) {
      const match = item.to === '/' ? pathname === '/' : pathname === item.to || pathname.startsWith(`${item.to}/`);
      if (match && (!best || item.to.length > best.item.to.length)) best = { group, item };
    }
  }
  return best;
}
