import { http } from 'msw';
import type { AdminCompany, Coupon, CustomerPrice, PriceList, PriceTier, Promotion } from '@/shared/api/types';
import { db, nextId } from '../db';
import { allVariants } from './catalog';
import { B, conflict, invalid, noContent, notFound, now, ok, paginate, sortBy, textMatch } from '../util';

const companies = (): AdminCompany[] => db.customers.map((c) => c.company).filter((c): c is AdminCompany => !!c);

export const commerceHandlers = [
  // Clientes
  http.get(`${B}/admin/customers`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    let rows = db.customers.filter((c) => textMatch(sp.get('q'), c.name, c.email, c.company?.legal_name));
    if (sp.get('type')) rows = rows.filter((c) => c.type === sp.get('type'));
    if (sp.get('is_active')) rows = rows.filter((c) => c.is_active === (sp.get('is_active') === '1'));
    rows = sortBy(rows, sp.get('sort'), { name: (c) => c.name, created_at: (c) => c.created_at, total_spent: (c) => c.stats.total_spent_cents, last_order_at: (c) => c.stats.last_order_at ?? '' });
    return Response.json(paginate(rows.map(({ addresses: _a, ...c }) => c), url));
  }),
  http.get(`${B}/admin/customers/:id`, ({ params }) => {
    const c = db.customers.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    return ok({
      ...c,
      addresses: [
        { uuid: 'a1', label: 'Loja', recipient_name: c.name, phone: c.phone, postal_code: '89010000', street: 'Rua das Flores', number: '123', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', city_ibge_code: '4202404', reference: null, is_default: true, formatted: 'Rua das Flores, 123 – Centro – Blumenau/SC – 89010-000', created_at: c.created_at },
      ],
    });
  }),
  http.patch(`${B}/admin/customers/:id`, async ({ params, request }) => {
    const c = db.customers.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    const b = (await request.json()) as { name?: string; phone?: string; price_list_id?: number | null };
    if (b.name !== undefined) c.name = b.name;
    if (b.phone !== undefined) c.phone = b.phone;
    if ('price_list_id' in b) {
      const pl = db.priceLists.find((p) => p.id === b.price_list_id);
      c.price_list = pl ? { id: pl.id, code: pl.code, name: pl.name } : null;
    }
    c.updated_at = now();
    return ok(c);
  }),
  http.post(`${B}/admin/customers/:id/block`, ({ params }) => {
    const c = db.customers.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    c.is_active = false;
    return ok(c);
  }),
  http.post(`${B}/admin/customers/:id/unblock`, ({ params }) => {
    const c = db.customers.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    c.is_active = true;
    return ok(c);
  }),
  http.post(`${B}/admin/customers/:id/password-reset`, () => ok({ message: 'Link de redefinição enviado.' }, 202)),
  http.post(`${B}/admin/customers/:id/reveal-document`, ({ params }) => {
    const c = db.customers.find((x) => x.id === Number(params.id));
    return ok({ cpf: c?.type === 'individual' ? '12345678909' : null, cnpj: c?.type === 'company' ? '12345678000190' : null });
  }),
  http.post(`${B}/admin/customers/:id/anonymize`, () => conflict('resource_in_use', 'O cliente tem pedidos em andamento.', { blockers: [{ type: 'order', id: 1, label: 'CV-000001' }] })),
  // Empresas
  http.get(`${B}/admin/companies`, ({ request }) => {
    const url = new URL(request.url);
    return Response.json(paginate(companies().filter((c) => textMatch(url.searchParams.get('q'), c.legal_name, c.trade_name)), url));
  }),
  http.get(`${B}/admin/companies/:id`, ({ params }) => {
    const c = companies().find((x) => x.id === Number(params.id));
    return c ? ok(c) : notFound();
  }),
  http.patch(`${B}/admin/companies/:id`, async ({ params, request }) => {
    const c = companies().find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    const b = (await request.json()) as Partial<AdminCompany> & { price_list_id?: number | null };
    const { price_list_id, ...rest } = b;
    Object.assign(c, rest);
    if (price_list_id !== undefined) {
      const pl = db.priceLists.find((p) => p.id === price_list_id);
      c.price_list = pl ? { id: pl.id, code: pl.code, name: pl.name } : null;
    }
    return ok(c);
  }),
  // Tabelas de preço
  http.get(`${B}/admin/price-lists`, () => ok(db.priceLists)),
  http.get(`${B}/admin/price-lists/:id`, ({ params }) => {
    const p = db.priceLists.find((x) => x.id === Number(params.id));
    return p ? ok(p) : notFound();
  }),
  http.post(`${B}/admin/price-lists`, async ({ request }) => {
    const b = (await request.json()) as Partial<PriceList>;
    if (db.priceLists.some((p) => p.code === b.code)) return invalid({ code: ['Código já utilizado.'] });
    const p: PriceList = { id: nextId(), code: b.code!, name: b.name!, kind: b.kind ?? 'custom', discount_bp: b.discount_bp ?? null, is_default: !!b.is_default, is_active: b.is_active ?? true, customers_count: 0, companies_count: 0, tiers_count: 0, created_at: now(), updated_at: now() };
    db.priceLists.push(p);
    return ok(p, 201);
  }),
  http.patch(`${B}/admin/price-lists/:id`, async ({ params, request }) => {
    const p = db.priceLists.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    Object.assign(p, await request.json(), { updated_at: now() });
    return ok(p);
  }),
  http.delete(`${B}/admin/price-lists/:id`, ({ params }) => {
    const p = db.priceLists.find((x) => x.id === Number(params.id));
    if (p?.is_default || (p && p.companies_count > 0)) return conflict('resource_in_use', 'Tabela padrão ou atribuída a clientes/empresas.', { blockers: [] });
    db.priceLists = db.priceLists.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  http.get(`${B}/admin/price-lists/:id/tiers`, ({ params, request }) => {
    const rows = db.tiers
      .filter((t) => t.price_list_id === Number(params.id))
      .map((t) => {
        const v = allVariants().find((x) => x.variant.id === t.variant_id)!;
        return { ...t, variant: { id: v.variant.id, sku: v.variant.sku, name: v.variant.name, sale_unit: v.product.sale_unit, price_cents: v.variant.price_cents, is_active: v.variant.is_active, product: { id: v.product.id, name: v.product.name } } };
      });
    return Response.json(paginate(rows, new URL(request.url)));
  }),
  // Faixas por variante
  http.get(`${B}/admin/variants/:variantId/price-tiers`, ({ params }) => {
    const vid = Number(params.variantId);
    const mine = db.tiers.filter((t) => t.variant_id === vid);
    return ok({
      base: mine.filter((t) => t.price_list_id === null),
      price_lists: db.priceLists.map((pl) => ({ price_list: pl, tiers: mine.filter((t) => t.price_list_id === pl.id) })),
    });
  }),
  http.put(`${B}/admin/variants/:variantId/price-tiers`, async ({ params, request }) => {
    const vid = Number(params.variantId);
    const b = (await request.json()) as { price_list_id: number | null; tiers: { min_quantity: number; price_cents: number }[] };
    for (let i = 1; i < b.tiers.length; i++) {
      if (b.tiers[i].price_cents > b.tiers[i - 1].price_cents) return invalid({ [`tiers.${i}.price_cents`]: ['O preço não pode aumentar com a quantidade.'] });
    }
    db.tiers = db.tiers.filter((t) => !(t.variant_id === vid && t.price_list_id === b.price_list_id));
    db.tiers.push(...b.tiers.map((t): PriceTier => ({ id: nextId(), variant_id: vid, price_list_id: b.price_list_id, min_quantity: Number(t.min_quantity), price_cents: t.price_cents })));
    const mine = db.tiers.filter((t) => t.variant_id === vid);
    return ok({ base: mine.filter((t) => t.price_list_id === null), price_lists: db.priceLists.map((pl) => ({ price_list: pl, tiers: mine.filter((t) => t.price_list_id === pl.id) })) });
  }),
  // Preços por cliente
  http.get(`${B}/admin/customer-prices`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    let rows = db.customerPrices;
    if (sp.get('customer_id')) rows = rows.filter((r) => r.customer?.id === Number(sp.get('customer_id')));
    if (sp.get('company_id')) rows = rows.filter((r) => r.company?.id === Number(sp.get('company_id')));
    return Response.json(paginate(rows, url));
  }),
  http.post(`${B}/admin/customer-prices`, async ({ request }) => {
    const b = (await request.json()) as { customer_id?: number; company_id?: number; variant_id: number; price_cents: number; starts_at: string | null; ends_at: string | null };
    const v = allVariants().find((x) => x.variant.id === b.variant_id);
    if (!v) return invalid({ variant_id: ['Selecione uma variante.'] });
    const cust = db.customers.find((c) => c.id === b.customer_id);
    const comp = companies().find((c) => c.id === b.company_id);
    const row: CustomerPrice = {
      id: nextId(), customer: cust ? { id: cust.id, name: cust.name, email: cust.email } : null, company: comp ? { id: comp.id, legal_name: comp.legal_name } : null,
      variant: { id: v.variant.id, sku: v.variant.sku, name: v.variant.name, sale_unit: v.product.sale_unit, price_cents: v.variant.price_cents, is_active: true, product: { id: v.product.id, name: v.product.name } },
      price_cents: b.price_cents, starts_at: b.starts_at, ends_at: b.ends_at, created_by: { id: db.me.user.id, name: db.me.user.name }, created_at: now(), updated_at: now(),
    };
    db.customerPrices.push(row);
    return ok(row, 201);
  }),
  http.patch(`${B}/admin/customer-prices/:id`, async ({ params, request }) => {
    const r = db.customerPrices.find((x) => x.id === Number(params.id));
    if (!r) return notFound();
    Object.assign(r, await request.json());
    return ok(r);
  }),
  http.delete(`${B}/admin/customer-prices/:id`, ({ params }) => {
    db.customerPrices = db.customerPrices.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  // Promoções
  http.get(`${B}/admin/promotions`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    return Response.json(paginate(db.promotions.filter((p) => textMatch(sp.get('q'), p.name) && (!sp.get('status') || p.status === sp.get('status'))), url));
  }),
  http.post(`${B}/admin/promotions`, async ({ request }) => {
    const b = (await request.json()) as Partial<Promotion>;
    const p = { ...(b as Promotion), id: nextId(), status: 'scheduled' as const, targets: { products: [], categories: [], brands: [] }, created_at: now(), updated_at: now(), deleted_at: null };
    db.promotions.push(p);
    return ok(p, 201);
  }),
  http.patch(`${B}/admin/promotions/:id`, async ({ params, request }) => {
    const p = db.promotions.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    Object.assign(p, await request.json(), { updated_at: now() });
    return ok(p);
  }),
  http.delete(`${B}/admin/promotions/:id`, ({ params }) => {
    db.promotions = db.promotions.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  http.post(`${B}/admin/promotions/:id/preview`, async ({ params, request }) => {
    const p = db.promotions.find((x) => x.id === Number(params.id));
    const { variant_ids } = (await request.json()) as { variant_ids: number[] };
    const rows = allVariants()
      .filter((x) => variant_ids.includes(x.variant.id))
      .map((x) => ({
        variant_id: x.variant.id, sku: x.variant.sku, base_price_cents: x.variant.price_cents,
        promo_price_cents: p?.discount_type === 'percent' ? Math.round((x.variant.price_cents * (10000 - p.value)) / 10000) : Math.max(1, x.variant.price_cents - (p?.value ?? 0)),
      }));
    return ok(rows);
  }),
  // Cupons
  http.get(`${B}/admin/coupons`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    return Response.json(paginate(db.coupons.filter((c) => textMatch(sp.get('q'), c.code) && (!sp.get('status') || c.status === sp.get('status')) && (!sp.get('type') || c.type === sp.get('type'))), url));
  }),
  http.post(`${B}/admin/coupons/generate-code`, () => ok({ code: Math.random().toString(36).slice(2, 10).toUpperCase() })),
  http.get(`${B}/admin/coupons/:id/redemptions`, ({ request }) =>
    Response.json(paginate([{ id: 1, order: { id: 1, number: 'CV-000001', status: 'paid' }, customer: { id: 1, name: 'Ana Souza' }, discount_cents: 1695, created_at: '2026-09-24T13:00:00Z', cancelled_at: null }], new URL(request.url))),
  ),
  http.post(`${B}/admin/coupons`, async ({ request }) => {
    const b = (await request.json()) as Partial<Coupon>;
    const code = (b.code ?? '').toUpperCase();
    if (db.coupons.some((c) => c.code === code)) return invalid({ code: ['Este código já existe.'] });
    const c = { ...(b as Coupon), code, id: nextId(), times_used: 0, status: 'active' as const, created_at: now(), updated_at: now(), deleted_at: null };
    db.coupons.push(c);
    return ok(c, 201);
  }),
  http.patch(`${B}/admin/coupons/:id`, async ({ params, request }) => {
    const c = db.coupons.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    Object.assign(c, await request.json(), { updated_at: now() });
    return ok(c);
  }),
  http.delete(`${B}/admin/coupons/:id`, ({ params }) => {
    db.coupons = db.coupons.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
];

