import { http, HttpResponse } from 'msw';
import type { AdminUser, Dashboard, ReportName, Role, SettingKey } from '@/shared/api/types';
import { PERMISSION_CATALOG } from '@/shared/auth/permissions';
import { addDaysISO, todayISO } from '@/shared/formatters/date';
import { db, nextId } from '../db';
import { allVariants, inventoryItem } from './catalog';
import { B, invalid, noContent, notFound, now, ok, paginate, textMatch } from '../util';

function dashboard(): Dashboard {
  const today = todayISO();
  const series = Array.from({ length: 30 }, (_, i) => {
    const date = addDaysISO(today, i - 29);
    const v = ((i * 7919) % 13) * 41000 + (i % 6 === 0 ? 0 : 60000);
    return { date, revenue_cents: i % 7 === 6 ? 0 : v, orders_paid: i % 7 === 6 ? 0 : 3 + (i % 5) };
  });
  const low = allVariants().filter((x) => x.variant.inventory.is_low_stock);
  return {
    generated_at: now(),
    sales: {
      today: { orders_paid: { value: 7, previous: 6, change_bp: 1667 }, revenue_cents: { value: 423000, previous: 377000, change_bp: 1220 } },
      month: { orders_paid: { value: 161, previous: 149, change_bp: 805 }, revenue_cents: { value: 9841000, previous: 9112000, change_bp: 800 } },
      avg_ticket_cents_month: { value: 61240, previous: 58880, change_bp: 401 },
      revenue_series_30d: series,
      top_products_30d: [
        { variant_id: 10, sku: 'VIN-BR-122-BR', name: 'Vinil Adesivo Branco 1,22 m', sale_unit: 'LINEAR_METER', quantity: 420, revenue_cents: 610000 },
        { variant_id: 20, sku: 'LON-FL-440', name: 'Lona Frontlight 440 g', sale_unit: 'SQUARE_METER', quantity: 310.5, revenue_cents: 837000 },
        { variant_id: 30, sku: 'ILH-0-CX', name: 'Ilhós nº 0 latão', sale_unit: 'BOX', quantity: 12, revenue_cents: 54000 },
      ],
    },
    queues: {
      pending_payment: db.orders.filter((o) => o.status === 'pending_payment').length,
      to_pick: db.orders.filter((o) => o.status === 'paid').length,
      to_ship: db.orders.filter((o) => o.status === 'processing' && o.shipping.method_type !== 'pickup').length,
      to_prepare_pickup: db.orders.filter((o) => o.status === 'processing' && o.shipping.method_type === 'pickup').length,
      ready_for_pickup: db.orders.filter((o) => o.status === 'ready_for_pickup').length,
      shipped: db.orders.filter((o) => o.status === 'shipped').length,
      cancellation_requests: db.orders.filter((o) => o.cancellation_request).length,
    },
    todays_deliveries: db.orders
      .filter((o) => ['paid', 'processing', 'shipped', 'ready_for_pickup'].includes(o.status))
      .map((o) => ({ order_id: o.id, number: o.number, status: o.status, shipping_method_type: o.shipping.method_type, shipping_method_name: o.shipping.method_name, district: o.shipping.address?.district ?? null, city: o.shipping.address?.city ?? null, estimated_delivery_date: o.shipping.estimated_delivery_date })),
    low_stock: { items: low.map((x) => inventoryItem(x.product, x.variant)), total: low.length },
  };
}

function report(name: ReportName, url: URL) {
  const sp = url.searchParams;
  const period = { date_from: sp.get('date_from') ?? addDaysISO(todayISO(), -29), date_to: sp.get('date_to') ?? todayISO(), group_by: (sp.get('group_by') as 'day' | null) ?? null, timezone: 'America/Sao_Paulo' as const };
  const days = Array.from({ length: 7 }, (_, i) => addDaysISO(period.date_to, i - 6));
  const base = { report: name, period, filters: {}, totals: null };
  switch (name) {
    case 'sales':
      return { ...base, summary: { orders_paid: 42, revenue_cents: 2572000, avg_ticket_cents: 61238, orders_refunded: 1 }, rows: days.map((d, i) => ({ period_start: d, orders_paid: 4 + i, orders_refunded: i === 3 ? 1 : 0, revenue_cents: 250000 + i * 31000, products_revenue_cents: 230000 + i * 30000, shipping_cents: 20000, discount_cents: 1500 * i, avg_ticket_cents: 60000 + i * 500 })) };
    case 'revenue':
      return { ...base, summary: { gross_cents: 2600000, discount_cents: 30000, shipping_cents: 140000, refunds_cents: 18950, net_cents: 2551050 }, rows: days.map((d, i) => ({ period_start: d, gross_cents: 300000 + i * 1000, discount_cents: 2000, shipping_cents: 20000, refunds_cents: i === 2 ? 18950 : 0, net_cents: 298000 + i * 1000 })) };
    case 'products':
    case 'margin':
      return {
        ...base,
        summary: name === 'products' ? { distinct_variants: 3, revenue_cents: 1501000 } : { revenue_cents: 1501000, cost_cents: 820000, margin_cents: 681000, cost_coverage_bp: 6667 },
        rows: allVariants().map(({ product, variant }, i) => ({ variant_id: variant.id, sku: variant.sku, product_name: product.name, variant_name: variant.name, sale_unit: product.sale_unit, quantity: [420, 310.5, 12][i] ?? 1, revenue_cents: [610000, 837000, 54000][i] ?? 0, orders_count: 10 - i, cost_cents: i === 2 ? null : 400000, margin_cents: i === 2 ? null : 210000, margin_bp: i === 2 ? null : 3443 })),
      };
    case 'customers':
      return { ...base, summary: { new_customers: 12, returning_customers: 30, individual_customers: 20, company_customers: 22 }, rows: db.customers.map((c) => ({ customer_id: c.id, name: c.name, type: c.type, orders_count: c.stats.orders_count, revenue_cents: c.stats.total_spent_cents, last_order_at: c.stats.last_order_at ?? now() })) };
    case 'inventory':
      return { ...base, summary: { variants: 3, low_stock_variants: 2, stock_value_cents: 450000 }, rows: allVariants().map(({ product, variant }) => ({ variant_id: variant.id, sku: variant.sku, product_name: product.name, sale_unit: product.sale_unit, on_hand: variant.inventory.on_hand, reserved: variant.inventory.reserved, available: variant.inventory.available, low_stock_threshold: variant.inventory.low_stock_threshold, is_low_stock: variant.inventory.is_low_stock, cost_cents: variant.cost_cents, stock_value_cents: variant.cost_cents === null ? null : Math.round(variant.cost_cents * variant.inventory.on_hand), sold_quantity: 12 })) };
    case 'inventory-movements':
      return { ...base, summary: { movements: db.movements.length }, rows: db.movements.map((m) => ({ variant_id: m.variant_id, sku: 'VIN-BR-122-BR', product_name: 'Vinil Adesivo Branco 1,22 m', type: m.type, movements_count: 1, quantity: m.quantity })) };
    case 'orders':
      return { ...base, summary: { created: 50, paid: 42, cancelled: 8, cancelled_payment_expired: 5, cancelled_customer: 2, cancelled_admin: 1, conversion_bp: 8400, cancellation_bp: 1600, median_fulfillment_hours: 20 }, rows: ['paid', 'processing', 'shipped', 'delivered', 'cancelled'].map((s, i) => ({ status: s, count: 10 - i })) };
    case 'shipping':
      return { ...base, summary: { orders: 42, shipping_revenue_cents: 84000, free_shipping_orders: 6 }, rows: [{ shipping_method_id: 2, method_name: 'Entrega própria', method_type: 'own_delivery', city: 'Blumenau', state: 'SC', orders_count: 30, shipping_revenue_cents: 60000, free_shipping_orders: 6, shipping_discount_cents: 12000 }, { shipping_method_id: 1, method_name: 'Retirada na loja', method_type: 'pickup', city: null, state: null, orders_count: 12, shipping_revenue_cents: 0, free_shipping_orders: 0, shipping_discount_cents: 0 }] };
    case 'coupons':
      return { ...base, summary: { uses: 15, discount_cents: 25000 }, rows: db.coupons.map((c) => ({ coupon_id: c.id, code: c.code, uses: c.times_used, discount_cents: c.times_used * 1500, orders_revenue_cents: c.times_used * 30000 })) };
  }
}

export const systemHandlers = [
  http.get(`${B}/admin/dashboard`, () => ok(dashboard())),
  http.get(`${B}/admin/reports/:report`, ({ params, request }) => {
    const url = new URL(request.url);
    const name = params.report as ReportName;
    if (url.searchParams.get('format') === 'csv') {
      const csv = '﻿Data;Pedidos;Faturamento\n24/09/2026;7;4230,00\n';
      return new HttpResponse(csv, { headers: { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': `attachment; filename="${name}_${url.searchParams.get('date_from')}_${url.searchParams.get('date_to')}.csv"` } });
    }
    if (name !== 'inventory' && (!url.searchParams.get('date_from') || !url.searchParams.get('date_to'))) return invalid({ date_from: ['Informe o período.'] });
    const r = report(name, url);
    return r ? ok(r) : notFound();
  }),
  // Usuários
  http.get(`${B}/admin/users`, ({ request }) => {
    const url = new URL(request.url);
    return Response.json(paginate(db.users.filter((u) => textMatch(url.searchParams.get('q'), u.name, u.email)), url));
  }),
  http.post(`${B}/admin/users`, async ({ request }) => {
    const b = (await request.json()) as { name: string; email: string; roles: string[] };
    if (db.users.some((u) => u.email === b.email)) return invalid({ email: ['Este e-mail já está em uso.'] });
    const u: AdminUser = { id: nextId(), name: b.name, email: b.email, roles: b.roles, is_active: true, last_login_at: null, created_at: now(), deleted_at: null };
    db.users.push(u);
    return ok(u, 201);
  }),
  http.patch(`${B}/admin/users/:id`, async ({ params, request }) => {
    const u = db.users.find((x) => x.id === Number(params.id));
    if (!u) return notFound();
    const b = (await request.json()) as Partial<AdminUser>;
    if (u.id === db.me.user.id && b.roles) return HttpResponse.json({ message: 'Você não pode alterar os próprios papéis.', code: 'forbidden' }, { status: 403 });
    Object.assign(u, b);
    return ok(u);
  }),
  http.post(`${B}/admin/users/:id/deactivate`, ({ params }) => {
    const u = db.users.find((x) => x.id === Number(params.id));
    if (!u) return notFound();
    if (u.id === db.me.user.id) return HttpResponse.json({ message: 'Você não pode desativar a si mesmo.', code: 'forbidden' }, { status: 403 });
    u.is_active = false;
    return ok(u);
  }),
  http.post(`${B}/admin/users/:id/activate`, ({ params }) => {
    const u = db.users.find((x) => x.id === Number(params.id));
    if (!u) return notFound();
    u.is_active = true;
    return ok(u);
  }),
  http.post(`${B}/admin/users/:id/password-reset`, () => ok({ message: 'Link enviado.' }, 202)),
  http.delete(`${B}/admin/users/:id`, ({ params }) => {
    db.users = db.users.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  // Papéis
  http.get(`${B}/admin/permissions`, () => ok(PERMISSION_CATALOG)),
  http.get(`${B}/admin/roles`, () => ok(db.roles)),
  http.post(`${B}/admin/roles`, async ({ request }) => {
    const b = (await request.json()) as Pick<Role, 'name' | 'permissions'>;
    if (!/^[a-z0-9-]{3,50}$/.test(b.name)) return invalid({ name: ['Use 3 a 50 caracteres: letras minúsculas, números e hífen.'] });
    const r: Role = { id: nextId(), name: b.name, label: b.name, is_system: false, permissions: b.permissions, users_count: 0 };
    db.roles.push(r);
    return ok(r, 201);
  }),
  http.patch(`${B}/admin/roles/:id`, async ({ params, request }) => {
    const r = db.roles.find((x) => x.id === Number(params.id));
    if (!r) return notFound();
    if (r.name === 'super-admin') return HttpResponse.json({ message: 'O papel Super Admin não pode ser editado.', code: 'forbidden' }, { status: 403 });
    Object.assign(r, await request.json());
    return ok(r);
  }),
  http.delete(`${B}/admin/roles/:id`, ({ params }) => {
    db.roles = db.roles.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  // Configurações
  http.get(`${B}/admin/settings`, () => ok(db.settings)),
  http.patch(`${B}/admin/settings`, async ({ request }) => {
    const b = (await request.json()) as { values: Partial<Record<SettingKey, unknown>> };
    const errs: Record<string, string[]> = {};
    for (const [k, v] of Object.entries(b.values)) {
      const s = db.settings.find((x) => x.key === k);
      if (!s) errs[`values.${k}`] = ['Chave desconhecida.'];
      else if (k === 'checkout.pix_expiry_minutes' && (Number(v) < 5 || Number(v) > 1440)) errs[`values.${k}`] = ['Use um valor entre 5 e 1440 minutos.'];
      else {
        s.value = v;
        s.updated_at = now();
        s.updated_by = { id: db.me.user.id, name: db.me.user.name };
      }
    }
    if (Object.keys(errs).length) return invalid(errs);
    return ok(db.settings);
  }),
  // Auditoria
  http.get(`${B}/admin/audit-logs`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    const rows = db.audit.filter(
      (a) =>
        (!sp.get('action') || a.action.startsWith(sp.get('action')!)) &&
        (!sp.get('actor_type') || a.actor.type === sp.get('actor_type')) &&
        (!sp.get('auditable_type') || a.auditable_type === sp.get('auditable_type')) &&
        (!sp.get('auditable_id') || a.auditable_id === Number(sp.get('auditable_id'))) &&
        (!sp.get('request_id') || a.request_id === sp.get('request_id')),
    );
    return Response.json(paginate(rows, url));
  }),
  http.get(`${B}/admin/audit-logs/:id`, ({ params }) => {
    const a = db.audit.find((x) => x.id === Number(params.id));
    return a ? ok(a) : notFound();
  }),
  http.get(`${B}/admin/failed-jobs`, ({ request }) => Response.json(paginate([{ uuid: 'f1', queue: 'notifications', job: 'SendOrderShippedEmail', exception_summary: 'Connection timed out (smtp)', failed_at: '2026-09-24T10:00:00Z' }], new URL(request.url)))),
];
