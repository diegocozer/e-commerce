import { http, HttpResponse } from 'msw';
import type { AdminOrder, AdminOrderListItem, AdminTransition, OrderStatus } from '@/shared/api/types';
import { hasPermission } from '@/shared/auth/me';
import { ORDER_STATUS, ORDER_STATUSES } from '@/shared/formatters/labels';
import { db, nextId } from '../db';
import { B, conflict, invalid, notFound, now, ok, paginate, sortBy, textMatch } from '../util';

const LABELS: Partial<Record<OrderStatus, string>> = {
  processing: 'Marcar em separação',
  shipped: 'Marcar como enviado',
  ready_for_pickup: 'Pronto para retirada',
  delivered: 'Marcar como entregue',
  picked_up: 'Marcar como retirado',
};

/** Máquina de estados de API §3.G.9, filtrada pelas permissões do admin do mock. */
export function computeTransitions(o: AdminOrder): AdminTransition[] {
  const pickup = o.shipping.method_type === 'pickup';
  const t = (to: OrderStatus, required: AdminTransition['required_fields'] = [], optional: AdminTransition['optional_fields'] = ['note']): AdminTransition => ({ to_status: to, label: LABELS[to]!, required_fields: required, optional_fields: optional });
  const fulfill = hasPermission(db.me, 'orders.fulfill');
  const list: AdminTransition[] = [];
  if (o.status === 'paid' && fulfill) list.push(t('processing'));
  if (o.status === 'processing' && fulfill) {
    if (pickup) list.push(t('ready_for_pickup'));
    else if (o.shipping.method_type === 'carrier') list.push(t('shipped', ['tracking_code'], ['note', 'tracking_url']));
    else list.push(t('shipped', [], ['note', 'tracking_code', 'tracking_url', 'carrier_name']));
  }
  if (o.status === 'shipped' && fulfill) list.push(t('delivered'));
  if (o.status === 'ready_for_pickup' && hasPermission(db.me, ['orders.fulfill', 'orders.pickup'])) list.push(t('picked_up', ['picked_up_by_name', 'picked_up_by_document']));
  return list;
}

function hydrate(o: AdminOrder): AdminOrder {
  const paidLike = o.status === 'paid' || o.status === 'processing';
  o.allowed_transitions = computeTransitions(o);
  o.can_cancel = (o.status === 'pending_payment' && hasPermission(db.me, 'orders.cancel_unpaid')) || (paidLike && hasPermission(db.me, 'orders.cancel_paid'));
  o.cancel_requires_refund = paidLike;
  o.status_label = ORDER_STATUS[o.status].label;
  return o;
}

function listItem(o: AdminOrder): AdminOrderListItem {
  return {
    id: o.id, uuid: o.uuid, number: o.number, status: o.status, payment_status: o.payment_status, payment_method: o.payment_method,
    customer: { id: o.customer.id, name: o.customer.name, type: o.customer.type, company_name: o.customer.company_name }, items_count: o.items.length,
    total_cents: o.totals.total_cents, shipping_method_name: o.shipping.method_name, shipping_method_type: o.shipping.method_type,
    shipping_city: o.shipping.address?.city ?? null, shipping_state: o.shipping.address?.state ?? null, placed_at: o.placed_at, paid_at: o.paid_at,
    expires_at: o.expires_at, has_cancellation_request: !!o.cancellation_request,
  };
}

function filter(url: URL, withStatus: boolean): AdminOrder[] {
  const sp = url.searchParams;
  return db.orders.filter((o) => {
    if (!textMatch(sp.get('q'), o.number, o.customer.name, o.customer.email, o.customer.company_name)) return false;
    if (withStatus && sp.get('status') && !sp.get('status')!.split(',').includes(o.status)) return false;
    if (sp.get('payment_status') && !sp.get('payment_status')!.split(',').includes(o.payment_status)) return false;
    if (sp.get('shipping_method_type') && !sp.get('shipping_method_type')!.split(',').includes(o.shipping.method_type)) return false;
    if (sp.get('customer_id') && o.customer.id !== Number(sp.get('customer_id'))) return false;
    if (withStatus && sp.get('cancellation_requested') === '1' && !o.cancellation_request) return false;
    return true;
  });
}

const find = (id: unknown) => db.orders.find((o) => o.id === Number(id));
const actor = () => ({ type: 'admin' as const, id: db.me.user.id, name: db.me.user.name });

export const orderHandlers = [
  http.get(`${B}/admin/orders/status-counts`, ({ request }) => {
    const rows = filter(new URL(request.url), false);
    const counts = Object.fromEntries(ORDER_STATUSES.map((s) => [s, rows.filter((o) => o.status === s).length]));
    return ok({ ...counts, all: rows.length, cancellation_requests: rows.filter((o) => o.cancellation_request).length });
  }),
  http.get(`${B}/admin/orders`, ({ request }) => {
    const url = new URL(request.url);
    const rows = sortBy(filter(url, true), url.searchParams.get('sort'), { placed_at: (o) => o.placed_at, paid_at: (o) => o.paid_at ?? '', total_cents: (o) => o.totals.total_cents });
    return Response.json(paginate(rows.map(listItem), url));
  }),
  http.get(`${B}/admin/orders/:id`, ({ params }) => {
    const o = find(params.id);
    return o ? ok(hydrate(o)) : notFound();
  }),
  http.patch(`${B}/admin/orders/:id`, async ({ params, request }) => {
    const o = find(params.id);
    if (!o) return notFound();
    const b = (await request.json()) as { internal_notes?: string | null; tracking_code?: string | null; tracking_url?: string | null };
    if ('internal_notes' in b) o.internal_notes = b.internal_notes ?? null;
    if ('tracking_code' in b) o.shipping.tracking_code = b.tracking_code ?? null;
    if ('tracking_url' in b) o.shipping.tracking_url = b.tracking_url ?? null;
    o.updated_at = now();
    return ok(hydrate(o));
  }),
  http.post(`${B}/admin/orders/:id/transitions`, async ({ params, request }) => {
    const o = find(params.id);
    if (!o) return notFound();
    const b = (await request.json()) as { to_status: OrderStatus; note?: string; tracking_code?: string; tracking_url?: string; picked_up_by_name?: string; picked_up_by_document?: string };
    const allowed = computeTransitions(o);
    const t = allowed.find((x) => x.to_status === b.to_status);
    if (!t) {
      return conflict('invalid_status_transition', `Transição de status inválida: ${ORDER_STATUS[o.status].label.toLowerCase()} → ${ORDER_STATUS[b.to_status]?.label.toLowerCase() ?? b.to_status}.`, { allowed_transitions: allowed.map((x) => x.to_status) });
    }
    const errs: Record<string, string[]> = {};
    if (t.required_fields.includes('tracking_code') && !b.tracking_code) errs.tracking_code = ['Informe o código de rastreio.'];
    if (t.required_fields.includes('picked_up_by_name') && (b.picked_up_by_name ?? '').length < 3) errs.picked_up_by_name = ['Informe o nome de quem retirou.'];
    if (t.required_fields.includes('picked_up_by_document') && (b.picked_up_by_document ?? '').length < 5) errs.picked_up_by_document = ['Informe o documento de quem retirou.'];
    if (Object.keys(errs).length) return invalid(errs);
    const from = o.status;
    o.status = b.to_status;
    const at = now();
    if (b.to_status === 'processing') o.processing_at = at;
    if (b.to_status === 'shipped') {
      o.shipped_at = at;
      o.shipping.tracking_code = b.tracking_code ?? o.shipping.tracking_code;
      o.shipping.tracking_url = b.tracking_url ?? o.shipping.tracking_url;
    }
    if (b.to_status === 'ready_for_pickup') o.ready_for_pickup_at = at;
    if (b.to_status === 'delivered') o.delivered_at = at;
    if (b.to_status === 'picked_up') {
      o.picked_up_at = at;
      o.shipping.picked_up_at = at;
      o.shipping.picked_up_by_name = b.picked_up_by_name ?? null;
      o.shipping.picked_up_by_document_masked = `***${(b.picked_up_by_document ?? '').slice(-3)}`;
    }
    o.status_history.unshift({ id: nextId(), from_status: from, to_status: o.status, actor: actor(), note: b.note ?? null, created_at: at });
    o.updated_at = at;
    return ok(hydrate(o));
  }),
  http.post(`${B}/admin/orders/:id/cancel`, async ({ params, request }) => {
    const o = find(params.id);
    if (!o) return notFound();
    const b = (await request.json()) as { reason?: string; confirm_refund?: boolean };
    hydrate(o);
    if (!o.can_cancel) return conflict('invalid_status_transition', 'Este pedido não pode ser cancelado.', { allowed_transitions: [] });
    if (!b.reason || b.reason.length < 3) return invalid({ reason: ['Informe o motivo (mín. 3 caracteres).'] });
    if (o.cancel_requires_refund && !b.confirm_refund) return invalid({ confirm_refund: ['Confirme o estorno.'] });
    const at = now();
    if (o.cancel_requires_refund && o.payments[0]) o.payments[0].refund = { status: 'pending', amount_cents: o.totals.total_cents, requested_at: at };
    o.status_history.unshift({ id: nextId(), from_status: o.status, to_status: 'cancelled', actor: actor(), note: b.reason, created_at: at });
    o.status = 'cancelled';
    o.cancelled_at = at;
    o.cancel_reason_code = 'admin';
    o.cancel_reason = b.reason;
    o.updated_at = at;
    return ok(hydrate(o));
  }),
  http.post(`${B}/admin/orders/:id/reveal-document`, ({ params }) => {
    const o = find(params.id);
    if (!o) return notFound();
    return ok({ customer_document: o.customer.type === 'company' ? '12345678000190' : '12345678909', picked_up_by_document: o.shipping.picked_up_by_name ? '123456789' : null });
  }),
  http.post(`${B}/admin/orders/:id/payments/reconcile`, ({ params }) => {
    const o = find(params.id);
    return o ? ok(hydrate(o)) : notFound();
  }),
  http.post(`${B}/admin/orders/:id/cancellation-request/dismiss`, ({ params }) => {
    const o = find(params.id);
    if (!o) return notFound();
    if (!o.cancellation_request) return HttpResponse.json({ message: 'Não há solicitação aberta.', code: 'invalid_status_transition', allowed_transitions: [] }, { status: 409 });
    o.cancellation_request = null;
    return ok(hydrate(o));
  }),
];
