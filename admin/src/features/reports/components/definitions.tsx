import type { PermissionName, ReportName, SaleUnit } from '@/shared/api/types';
import { formatDate, formatDateTime } from '@/shared/formatters/date';
import { CUSTOMER_TYPE, MOVEMENT_TYPE, ORDER_STATUS, SHIPPING_METHOD_TYPE } from '@/shared/formatters/labels';
import { formatBp, formatBRL } from '@/shared/formatters/money';
import { formatQuantity } from '@/shared/formatters/quantity';
import type { AnyRow } from '../api';

type Fmt = (v: AnyRow[string], row: AnyRow) => string;
const money: Fmt = (v) => (v === null ? '—' : formatBRL(Number(v)));
const int: Fmt = (v) => (v === null ? '—' : new Intl.NumberFormat('pt-BR').format(Number(v)));
const bp: Fmt = (v) => (v === null ? '—' : formatBp(Number(v)));
const qty: Fmt = (v, r) => (v === null ? '—' : formatQuantity(Number(v), r.sale_unit as SaleUnit));
const date: Fmt = (v) => formatDate(v as string);
const text: Fmt = (v) => (v === null ? '—' : String(v));

export interface ReportDef {
  name: ReportName;
  label: string;
  perms: PermissionName[];
  groupBy?: boolean;
  noPeriod?: boolean;
  filters?: ('category' | 'brand' | 'shipping_method' | 'status')[];
  chart?: { x: string; y: string; label: string };
  summary: { key: string; label: string; fmt: Fmt }[];
  columns: { key: string; header: string; fmt: Fmt; align?: 'right' }[];
  emptyHint?: string;
}

const SALES: PermissionName[] = ['reports.view', 'reports.sales'];
const INV: PermissionName[] = ['reports.view', 'reports.inventory'];
const ALL: PermissionName[] = ['reports.view'];

export const REPORTS: ReportDef[] = [
  {
    name: 'sales', label: 'Vendas por período', perms: SALES, groupBy: true, chart: { x: 'period_start', y: 'revenue_cents', label: 'Faturamento' },
    summary: [{ key: 'orders_paid', label: 'Pedidos pagos', fmt: int }, { key: 'revenue_cents', label: 'Faturamento', fmt: money }, { key: 'avg_ticket_cents', label: 'Ticket médio', fmt: money }, { key: 'orders_refunded', label: 'Estornados', fmt: int }],
    columns: [
      { key: 'period_start', header: 'Período', fmt: date }, { key: 'orders_paid', header: 'Pedidos pagos', fmt: int, align: 'right' }, { key: 'orders_refunded', header: 'Estornados', fmt: int, align: 'right' },
      { key: 'products_revenue_cents', header: 'Produtos', fmt: money, align: 'right' }, { key: 'shipping_cents', header: 'Frete', fmt: money, align: 'right' }, { key: 'discount_cents', header: 'Descontos', fmt: money, align: 'right' },
      { key: 'revenue_cents', header: 'Faturamento', fmt: money, align: 'right' }, { key: 'avg_ticket_cents', header: 'Ticket médio', fmt: money, align: 'right' },
    ],
  },
  {
    name: 'products', label: 'Produtos vendidos', perms: SALES, filters: ['category', 'brand'],
    summary: [{ key: 'distinct_variants', label: 'Variantes vendidas', fmt: int }, { key: 'revenue_cents', label: 'Receita', fmt: money }],
    columns: [
      { key: 'sku', header: 'SKU', fmt: text }, { key: 'product_name', header: 'Produto', fmt: text }, { key: 'variant_name', header: 'Variante', fmt: text },
      { key: 'quantity', header: 'Quantidade', fmt: qty, align: 'right' }, { key: 'orders_count', header: 'Pedidos', fmt: int, align: 'right' }, { key: 'revenue_cents', header: 'Receita', fmt: money, align: 'right' },
    ],
    emptyHint: 'Quantidades são exibidas com a unidade de cada produto (não somamos unidades diferentes).',
  },
  {
    name: 'revenue', label: 'Faturamento', perms: ALL, groupBy: true, chart: { x: 'period_start', y: 'net_cents', label: 'Líquido' },
    summary: [{ key: 'gross_cents', label: 'Bruto', fmt: money }, { key: 'discount_cents', label: 'Descontos', fmt: money }, { key: 'shipping_cents', label: 'Frete', fmt: money }, { key: 'refunds_cents', label: 'Estornos', fmt: money }, { key: 'net_cents', label: 'Líquido', fmt: money }],
    columns: [
      { key: 'period_start', header: 'Período', fmt: date }, { key: 'gross_cents', header: 'Bruto', fmt: money, align: 'right' }, { key: 'discount_cents', header: 'Descontos', fmt: money, align: 'right' },
      { key: 'shipping_cents', header: 'Frete', fmt: money, align: 'right' }, { key: 'refunds_cents', header: 'Estornos', fmt: money, align: 'right' }, { key: 'net_cents', header: 'Líquido', fmt: money, align: 'right' },
    ],
  },
  {
    name: 'customers', label: 'Clientes', perms: ALL,
    summary: [{ key: 'new_customers', label: 'Novos', fmt: int }, { key: 'returning_customers', label: 'Recorrentes', fmt: int }, { key: 'individual_customers', label: 'PF', fmt: int }, { key: 'company_customers', label: 'PJ', fmt: int }],
    columns: [
      { key: 'name', header: 'Cliente', fmt: text }, { key: 'type', header: 'Tipo', fmt: (v) => CUSTOMER_TYPE[v as 'individual'] ?? String(v) }, { key: 'orders_count', header: 'Pedidos', fmt: int, align: 'right' },
      { key: 'revenue_cents', header: 'Receita', fmt: money, align: 'right' }, { key: 'last_order_at', header: 'Último pedido', fmt: (v) => formatDateTime(v as string) },
    ],
  },
  {
    name: 'inventory', label: 'Estoque', perms: INV, noPeriod: false,
    summary: [{ key: 'variants', label: 'Variantes', fmt: int }, { key: 'low_stock_variants', label: 'Com estoque baixo', fmt: int }, { key: 'stock_value_cents', label: 'Valor em estoque (custo)', fmt: money }],
    columns: [
      { key: 'sku', header: 'SKU', fmt: text }, { key: 'product_name', header: 'Produto', fmt: text }, { key: 'on_hand', header: 'Em mãos', fmt: qty, align: 'right' }, { key: 'reserved', header: 'Reservado', fmt: qty, align: 'right' },
      { key: 'available', header: 'Disponível', fmt: qty, align: 'right' }, { key: 'sold_quantity', header: 'Vendido no período', fmt: qty, align: 'right' },
      { key: 'stock_value_cents', header: 'Valor (custo)', fmt: money, align: 'right' }, { key: 'is_low_stock', header: 'Baixo', fmt: (v) => (v ? '⚠ Sim' : 'Não') },
    ],
  },
  {
    name: 'inventory-movements', label: 'Movimentos de estoque', perms: INV,
    summary: [{ key: 'movements', label: 'Movimentos', fmt: int }],
    columns: [
      { key: 'sku', header: 'SKU', fmt: text }, { key: 'product_name', header: 'Produto', fmt: text }, { key: 'type', header: 'Tipo', fmt: (v) => MOVEMENT_TYPE[v as 'in']?.label ?? String(v) },
      { key: 'movements_count', header: 'Movimentos', fmt: int, align: 'right' }, { key: 'quantity', header: 'Quantidade', fmt: (v) => new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 }).format(Number(v)), align: 'right' },
    ],
  },
  {
    name: 'orders', label: 'Pedidos', perms: ALL, filters: ['status'],
    summary: [
      { key: 'created', label: 'Criados', fmt: int }, { key: 'paid', label: 'Pagos', fmt: int }, { key: 'cancelled', label: 'Cancelados', fmt: int }, { key: 'conversion_bp', label: 'Conversão', fmt: bp },
      { key: 'cancellation_bp', label: 'Cancelamento', fmt: bp }, { key: 'median_fulfillment_hours', label: 'Mediana pago→enviado', fmt: (v) => (v === null ? '—' : `${v} h`) },
      { key: 'cancelled_payment_expired', label: 'Cancel. PIX expirado', fmt: int }, { key: 'cancelled_customer', label: 'Cancel. cliente', fmt: int }, { key: 'cancelled_admin', label: 'Cancel. loja', fmt: int },
    ],
    columns: [{ key: 'status', header: 'Status', fmt: (v) => ORDER_STATUS[v as 'paid']?.label ?? String(v) }, { key: 'count', header: 'Pedidos', fmt: int, align: 'right' }],
  },
  {
    name: 'shipping', label: 'Frete', perms: ALL, filters: ['shipping_method'],
    summary: [{ key: 'orders', label: 'Pedidos', fmt: int }, { key: 'shipping_revenue_cents', label: 'Receita de frete', fmt: money }, { key: 'free_shipping_orders', label: 'Com frete grátis', fmt: int }],
    columns: [
      { key: 'method_name', header: 'Método', fmt: text }, { key: 'method_type', header: 'Tipo', fmt: (v) => SHIPPING_METHOD_TYPE[v as 'pickup'] ?? String(v) },
      { key: 'city', header: 'Cidade', fmt: (v, r) => (v ? `${String(v)}/${String(r.state)}` : '—') }, { key: 'orders_count', header: 'Pedidos', fmt: int, align: 'right' },
      { key: 'shipping_revenue_cents', header: 'Receita', fmt: money, align: 'right' }, { key: 'free_shipping_orders', header: 'Grátis', fmt: int, align: 'right' }, { key: 'shipping_discount_cents', header: 'Frete concedido', fmt: money, align: 'right' },
    ],
  },
  {
    name: 'margin', label: 'Margem', perms: ALL, filters: ['category', 'brand'],
    summary: [{ key: 'revenue_cents', label: 'Receita', fmt: money }, { key: 'cost_cents', label: 'Custo', fmt: money }, { key: 'margin_cents', label: 'Margem', fmt: money }, { key: 'cost_coverage_bp', label: 'Receita com custo cadastrado', fmt: bp }],
    columns: [
      { key: 'sku', header: 'SKU', fmt: text }, { key: 'product_name', header: 'Produto', fmt: text }, { key: 'quantity', header: 'Quantidade', fmt: qty, align: 'right' }, { key: 'revenue_cents', header: 'Receita', fmt: money, align: 'right' },
      { key: 'cost_cents', header: 'Custo', fmt: money, align: 'right' }, { key: 'margin_cents', header: 'Margem', fmt: money, align: 'right' }, { key: 'margin_bp', header: 'Margem %', fmt: bp, align: 'right' },
    ],
    emptyHint: 'Linhas sem custo aparecem com "—". Cadastre o custo das variantes para calcular a margem.',
  },
  {
    name: 'coupons', label: 'Cupons', perms: ALL,
    summary: [{ key: 'uses', label: 'Usos', fmt: int }, { key: 'discount_cents', label: 'Desconto concedido', fmt: money }],
    columns: [{ key: 'code', header: 'Cupom', fmt: text }, { key: 'uses', header: 'Usos', fmt: int, align: 'right' }, { key: 'discount_cents', header: 'Desconto', fmt: money, align: 'right' }, { key: 'orders_revenue_cents', header: 'Receita dos pedidos', fmt: money, align: 'right' }],
  },
];
