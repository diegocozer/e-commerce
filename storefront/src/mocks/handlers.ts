import { http, HttpResponse, type JsonBodyType } from 'msw';
import type {
  Address,
  Cart,
  CartItem,
  CheckoutSummary,
  Customer,
  OrderDetail,
  OrderStatusPoll,
  OrderSummary,
  ProductCard,
  ShippingQuote,
} from '@/shared/api/types';
import { formatCEP } from '@/shared/formatters/postalCode';
import { milliToNumber, toMilli } from '@/shared/saleUnit/decimal';
import type { LineBody } from '@/shared/saleUnit/configuration';
import {
  DEMO_PASSWORD,
  PIX_QR_BASE64,
  categoryTree,
  demoAddress,
  demoCustomer,
  findSeed,
  flatCategories,
  productCard,
  productDetail,
  productSeeds,
  settings,
  shippingOptions,
  stock,
  categoryRef,
} from './data';
import { buildCartItem, buildPreview, validateLine, type LineInputBody, type StoredLine } from './pricing';

const api = (p: string) => `*/api/v1${p}`;
const ok = <T extends JsonBodyType>(data: T, status = 200, headers?: Record<string, string>) => HttpResponse.json({ data } as JsonBodyType, { status, headers });
const err = (status: number, body: Record<string, unknown>) => HttpResponse.json(body as JsonBodyType, { status });
const uuid = () => crypto.randomUUID();

// ---------- estado em memória ----------
interface CartState {
  token: string | null;
  lines: StoredLine[];
  coupon: string | null;
  postalCode: string | null;
  quotes: Map<string, { quote: ShippingQuote; hash: string }>;
}
interface MockState {
  session: Customer | null;
  guestCarts: Map<string, CartState>;
  customerCart: CartState;
  addresses: Address[];
  orders: OrderDetail[];
  idempotency: Map<string, { fingerprint: string; uuid: string }>;
  nextLineId: number;
  orderSeq: number;
}

function emptyCart(token: string | null): CartState {
  return { token, lines: [], coupon: null, postalCode: null, quotes: new Map() };
}

export const mockState: MockState = createState();
function createState(): MockState {
  return { session: null, guestCarts: new Map(), customerCart: emptyCart(null), addresses: [structuredClone(demoAddress)], orders: [], idempotency: new Map(), nextLineId: 100, orderSeq: 0 };
}
export function resetMockState(opts: { loggedIn?: boolean } = {}): void {
  Object.assign(mockState, createState());
  for (const p of productSeeds) for (const v of p.variants) stock.set(v.id, v.stock);
  if (opts.loggedIn) mockState.session = structuredClone(demoCustomer);
}

function currentCart(request: Request, create: boolean): { cart: CartState | null; created: boolean; notFound: boolean } {
  if (mockState.session) return { cart: mockState.customerCart, created: false, notFound: false };
  const token = request.headers.get('X-Cart-Token');
  if (token) {
    const c = mockState.guestCarts.get(token);
    if (!c) return { cart: null, created: false, notFound: true };
    return { cart: c, created: false, notFound: false };
  }
  if (!create) return { cart: null, created: false, notFound: false };
  const c = emptyCart(uuid());
  mockState.guestCarts.set(c.token!, c);
  return { cart: c, created: true, notFound: false };
}

const COUPONS: Record<string, { type: 'percent' | 'free_shipping'; bp: number; min: number; description: string }> = {
  PROMO10: { type: 'percent', bp: 1000, min: 0, description: '10% de desconto' },
  FRETEGRATIS: { type: 'free_shipping', bp: 0, min: 10000, description: 'Frete grátis' },
};

function cartHash(c: CartState): string {
  return JSON.stringify(c.lines.map((l) => [l.variant_id, l.body]));
}

function buildCart(c: CartState, selection?: { quoteId: string; optionId: string } | null): Cart {
  const sums = new Map<number, number>();
  for (const l of c.lines) {
    const res = validateLine({ variant_id: l.variant_id, ...l.body });
    if (res.ok) sums.set(l.variant_id, (sums.get(l.variant_id) ?? 0) + res.valid.billableMilli);
  }
  const items: CartItem[] = c.lines.map((l) => buildCartItem(l, sums.get(l.variant_id) ?? 0));
  const subtotal = items.reduce((s, i) => s + (i.status === 'ok' || i.status === 'insufficient_stock' ? (i.line_total_cents ?? 0) : 0), 0);
  const cdef = c.coupon ? COUPONS[c.coupon] : null;
  const couponValid = Boolean(cdef && subtotal >= cdef.min);
  const discount = cdef && couponValid && cdef.type === 'percent' ? Math.round((subtotal * cdef.bp) / 10000) : 0;
  let shipping: number | null = null;
  let shippingDiscount: number | null = null;
  let selectionOut: Cart['shipping_selection'] = null;
  if (selection) {
    const q = c.quotes.get(selection.quoteId);
    const option = q?.quote.options.find((o) => o.option_id === selection.optionId);
    const valid = Boolean(q && option && q.hash === cartHash(c) && Date.parse(q.quote.expires_at ?? '') > Date.now());
    if (option) {
      selectionOut = { quote_id: selection.quoteId, option_id: selection.optionId, option, valid, issue_code: valid ? null : 'shipping_quote_expired' };
      if (valid) {
        shipping = option.price_cents;
        shippingDiscount = cdef?.type === 'free_shipping' && couponValid ? option.price_cents : 0;
      }
    }
  }
  const blocking: Cart['blocking_reasons'] = [];
  if (!items.length) blocking.push('cart_empty');
  if (items.some((i) => i.status === 'unavailable')) blocking.push('item_unavailable');
  if (items.some((i) => i.status === 'insufficient_stock')) blocking.push('item_insufficient_stock');
  if (items.some((i) => i.status === 'invalid_quantity')) blocking.push('item_invalid_quantity');
  if (cdef && !couponValid) blocking.push('coupon_invalid');
  return {
    token: mockState.session ? null : c.token,
    owner: mockState.session ? 'customer' : 'guest',
    items,
    items_count: items.length,
    coupon: c.coupon
      ? {
          code: c.coupon, description: cdef?.description ?? null, type: cdef?.type ?? 'percent', valid: couponValid,
          reason_code: couponValid ? null : 'min_order_not_met', message: couponValid ? null : 'Pedido mínimo para este cupom: R$ 100,00.',
          discount_cents: discount, free_shipping: cdef?.type === 'free_shipping',
        }
      : null,
    postal_code: c.postalCode,
    totals: { subtotal_cents: subtotal, discount_cents: discount, shipping_cents: shipping, shipping_discount_cents: shippingDiscount, total_cents: subtotal - discount + ((shipping ?? 0) - (shippingDiscount ?? 0)) },
    shipping_selection: selectionOut,
    total_weight_grams: items.reduce((s, i) => s + i.weight_grams, 0),
    free_shipping_progress: { threshold_cents: 50000, remaining_cents: Math.max(0, 50000 - subtotal), text: settings.free_shipping_banner!.text },
    price_list: null,
    can_checkout: blocking.length === 0,
    blocking_reasons: blocking,
    has_price_changes: items.some((i) => i.warnings.some((w) => w.code === 'price_changed')),
    updated_at: new Date().toISOString(),
  };
}

function cartResponse(c: CartState, status = 200, created = false) {
  return ok(buildCart(c), status, created && c.token ? { 'X-Cart-Token': c.token } : undefined);
}

function stockError(variantId: number, requestedMilli: number) {
  const v = findVariantDetail(variantId);
  const available = stock.get(variantId) ?? 0;
  return err(409, {
    message: `Estoque insuficiente para ${v.product} — ${v.variant}. Disponível: ${available}.`,
    code: 'insufficient_stock',
    items: [{ cart_item_id: null, variant_id: variantId, sku: v.sku, product_name: v.product, requested_quantity: milliToNumber(requestedMilli), available_quantity: available }],
  });
}

function findVariantDetail(variantId: number) {
  for (const p of productSeeds) {
    const v = p.variants.find((x) => x.id === variantId);
    if (v) return { product: p.name, variant: v.name, sku: v.sku };
  }
  return { product: '', variant: '', sku: '' };
}

function lineKey(body: LineBody): string {
  return 'quantity' in body ? 'q' : `${body.width_m ?? 'fixed'}x${body.height_m}`;
}

function quoteFor(c: CartState, postalCode: string): ShippingQuote {
  const cart = buildCart(c);
  const pickupOnly = c.lines.some((l) => productSeeds.find((p) => p.variants.some((v) => v.id === l.variant_id))?.pickup_only);
  const quote: ShippingQuote = {
    quote_id: uuid(),
    expires_at: new Date(Date.now() + 30 * 60_000).toISOString(),
    destination: { postal_code: postalCode, city: postalCode.startsWith('890') ? 'Blumenau' : 'São Paulo', state: postalCode.startsWith('890') ? 'SC' : 'SP' },
    options: shippingOptions(cart.totals.subtotal_cents, Boolean(pickupOnly)),
    notice: pickupOnly ? 'pickup_only_items' : null,
    message: null,
    total_weight_grams: cart.total_weight_grams,
  };
  c.quotes.set(quote.quote_id!, { quote, hash: cartHash(c) });
  c.postalCode = postalCode;
  return quote;
}

const requireSession = () => (mockState.session ? null : err(401, { message: 'Não autenticado.', code: 'unauthenticated' }));

function summaryOf(o: OrderDetail): OrderSummary {
  const { uuid: u, number, status, status_label, payment_status, payment_method, placed_at, expires_at, items_count, total_cents, shipping_method_name, shipping_method_type, tracking_code, allowed_actions } = o;
  return { uuid: u, number, status, status_label, payment_status, payment_method, placed_at, expires_at, items_count, total_cents, shipping_method_name, shipping_method_type, tracking_code, allowed_actions };
}

function checkoutSummary(addressUuid: string, quoteId: string | null, optionId: string | null): CheckoutSummary | { error: Response } {
  const c = mockState.customerCart;
  const address = mockState.addresses.find((a) => a.uuid === addressUuid);
  if (!address) return { error: err(422, { message: 'Endereço inválido.', errors: { address_uuid: ['Endereço inválido.'] } }) };
  const cart = buildCart(c, quoteId && optionId ? { quoteId, optionId } : null);
  const blocking: CheckoutSummary['blocking'] = [];
  if (!cart.items.length) blocking.push({ code: 'cart_empty', message: 'Seu carrinho está vazio.' });
  if (!quoteId) blocking.push({ code: 'shipping_required', message: 'Escolha uma opção de entrega.' });
  else if (cart.shipping_selection && !cart.shipping_selection.valid) blocking.push({ code: 'shipping_quote_expired', message: 'A cotação de frete expirou.' });
  const option = cart.shipping_selection?.valid ? cart.shipping_selection.option : null;
  const s = mockState.session!;
  return {
    items: cart.items,
    coupon: cart.coupon,
    totals: { subtotal_cents: cart.totals.subtotal_cents, discount_cents: cart.totals.discount_cents, shipping_cents: cart.totals.shipping_cents, shipping_discount_cents: cart.totals.shipping_discount_cents ?? 0, total_cents: cart.totals.total_cents },
    total_weight_grams: cart.total_weight_grams,
    address,
    shipping_option: option,
    shipping_quote: null,
    payment_method: 'pix',
    payment_expires_in_minutes: 30,
    billing: { customer_type: s.type, name: s.name, email: s.email, document: s.company?.cnpj ?? s.cpf ?? '', phone: s.phone, company_name: s.company?.legal_name ?? null, state_registration: s.company?.state_registration ?? null },
    can_place_order: blocking.length === 0 && cart.can_checkout,
    blocking,
  };
}

function productList(url: URL) {
  const q = url.searchParams.get('q')?.toLowerCase().trim() ?? '';
  if (url.searchParams.has('q') && (q.length < 2 || q.length > 100)) return err(422, { message: 'A busca deve ter entre 2 e 100 caracteres.', errors: { q: ['A busca deve ter entre 2 e 100 caracteres.'] } });
  const catSlugs = url.searchParams.get('category')?.split(',').filter(Boolean) ?? [];
  const brands = url.searchParams.get('brand')?.split(',').filter(Boolean) ?? [];
  const unit = url.searchParams.get('sale_unit');
  const inStock = url.searchParams.get('in_stock') === '1';
  const featured = url.searchParams.get('featured') === '1';
  const onSale = url.searchParams.get('on_sale') === '1';
  const pmin = Number(url.searchParams.get('price_min_cents') ?? NaN);
  const pmax = Number(url.searchParams.get('price_max_cents') ?? NaN);
  const catIds = new Set<number>();
  for (const slug of catSlugs) {
    const node = flatCategories().find((c) => c.slug === slug);
    if (node) [node, ...node.children].forEach((n) => catIds.add(n.id));
    const parent = categoryTree.find((c) => c.children.some((ch) => ch.slug === slug));
    if (parent && slug === 'vinil-adesivo') catIds.add(parent.id);
  }
  let seeds = productSeeds.filter((p) => {
    if (q && !`${p.name} ${p.variants.map((v) => v.sku).join(' ')} ${p.brand?.name ?? ''}`.toLowerCase().includes(q)) return false;
    if (catSlugs.length && !catIds.has(p.category)) return false;
    if (brands.length && !brands.includes(p.brand?.slug ?? '')) return false;
    if (unit && p.sale_unit !== unit) return false;
    if (featured && !p.featured) return false;
    if (onSale && !p.promo) return false;
    if (inStock && p.variants.every((v) => (stock.get(v.id) ?? 0) <= 0)) return false;
    const price = Math.min(...p.variants.map((v) => v.price));
    if (!Number.isNaN(pmin) && price < pmin) return false;
    if (!Number.isNaN(pmax) && price > pmax) return false;
    return true;
  });
  const sort = url.searchParams.get('sort') ?? (q ? 'relevance' : 'best_selling');
  const minPrice = (p: (typeof productSeeds)[number]) => Math.min(...p.variants.map((v) => v.price));
  if (sort === 'price') seeds = [...seeds].sort((a, b) => minPrice(a) - minPrice(b));
  else if (sort === '-price') seeds = [...seeds].sort((a, b) => minPrice(b) - minPrice(a));
  else if (sort === 'name') seeds = [...seeds].sort((a, b) => a.name.localeCompare(b.name));
  else if (sort === 'best_selling') seeds = [...seeds].sort((a, b) => (b.best ?? 0) - (a.best ?? 0));
  const perPage = Number(url.searchParams.get('per_page') ?? 24);
  const page = Math.max(1, Number(url.searchParams.get('page') ?? 1));
  const cards: ProductCard[] = seeds.map(productCard);
  const data = cards.slice((page - 1) * perPage, page * perPage);
  const lastPage = Math.max(1, Math.ceil(cards.length / perPage));
  const exact = q ? productSeeds.flatMap((p) => p.variants.filter((v) => v.sku.toLowerCase() === q).map((v) => ({ p, v })))[0] : undefined;
  const count = <K extends string>(keyOf: (p: (typeof seeds)[number]) => K | null) => {
    const m = new Map<K, number>();
    seeds.forEach((p) => { const k = keyOf(p); if (k) m.set(k, (m.get(k) ?? 0) + 1); });
    return m;
  };
  return HttpResponse.json({
    data,
    links: { first: '?page=1', last: `?page=${lastPage}`, prev: page > 1 ? `?page=${page - 1}` : null, next: page < lastPage ? `?page=${page + 1}` : null },
    meta: { current_page: page, from: data.length ? (page - 1) * perPage + 1 : null, last_page: lastPage, path: '/api/v1/products', per_page: perPage, to: data.length ? (page - 1) * perPage + data.length : null, total: cards.length, links: [] },
    facets: {
      categories: [...count((p) => categoryRef(p.category).slug)].map(([slug, n]) => ({ slug, name: flatCategories().find((c) => c.slug === slug)!.name, count: n })),
      brands: [...count((p) => p.brand?.slug ?? null)].map(([slug, n]) => ({ slug, name: productSeeds.find((p) => p.brand?.slug === slug)!.brand!.name, count: n })),
      sale_units: [...count((p) => p.sale_unit)].map(([value, n]) => ({ value, label: productCard(productSeeds.find((p) => p.sale_unit === value)!).sale_unit_label, count: n })),
      price_range_cents: seeds.length ? { min: Math.min(...seeds.map(minPrice)), max: Math.max(...seeds.map(minPrice)) } : null,
    },
    search: q ? { q, exact_sku_match: exact ? { variant_id: exact.v.id, sku: exact.v.sku, product_slug: exact.p.slug, url_path: productDetail(exact.p).url_path } : null } : null,
  } as JsonBodyType);
}

export const handlers = [
  http.get('*/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204, headers: { 'Set-Cookie': 'XSRF-TOKEN=mock-xsrf-token; Path=/' } })),

  http.get(api('/settings/public'), () => ok(settings as unknown as JsonBodyType)),
  http.get(api('/pages/:slug'), ({ params }) =>
    ['sobre', 'termos', 'privacidade', 'trocas'].includes(String(params.slug))
      ? ok({ slug: String(params.slug), title: { sobre: 'Sobre nós', termos: 'Termos de uso', privacidade: 'Política de privacidade', trocas: 'Trocas e devoluções' }[String(params.slug)] ?? '', body_text: 'Texto institucional de exemplo.\n\nSegundo parágrafo.', updated_at: '2026-09-01T12:00:00Z' })
      : err(404, { message: 'Não encontrado.', code: 'not_found' }),
  ),
  http.get(api('/categories'), () => ok(categoryTree as unknown as JsonBodyType)),
  http.get(api('/categories/:slug'), ({ params }) => {
    const node = flatCategories().find((c) => c.slug === params.slug);
    if (!node) return err(404, { message: 'Categoria não encontrada.', code: 'not_found' });
    const parent = categoryTree.find((c) => c.children.some((ch) => ch.id === node.id));
    return ok({
      id: node.id, parent_id: parent?.id ?? null, name: node.name, slug: node.slug, url_path: node.url_path, description_html: null, image_url: null,
      breadcrumbs: [{ name: 'Início', url_path: '/' }, ...(parent ? [{ name: parent.name, url_path: parent.url_path }] : []), { name: node.name, url_path: null }],
      children: node.children,
      seo: { title: `${node.name} | Comunika Suprimentos`, description: `${node.name} para comunicação visual.`, canonical_path: node.url_path, canonical_url: `https://loja.exemplo.com.br${node.url_path}`, robots: 'index,follow', og_image_url: null, json_ld: [] },
    } as unknown as JsonBodyType);
  }),
  http.get(api('/brands'), () => ok([{ id: 2, name: 'VinilSul', slug: 'vinilsul', logo_url: null }, { id: 3, name: 'Imprimax', slug: 'imprimax', logo_url: null }])),
  http.get(api('/products/autocomplete'), ({ request }) => {
    const q = (new URL(request.url).searchParams.get('q') ?? '').toLowerCase();
    const products = productSeeds
      .filter((p) => p.name.toLowerCase().includes(q) || p.variants.some((v) => v.sku.toLowerCase().includes(q)))
      .slice(0, 8)
      .map((p) => {
        const d = productDetail(p);
        const sku = p.variants.find((v) => v.sku.toLowerCase().startsWith(q))?.sku ?? null;
        return { id: d.id, name: d.name, slug: d.slug, url_path: d.url_path, image_url: d.images[0]?.urls?.w300 ?? null, matched_sku: sku, unit_price_cents: d.variants[0].price.unit_price_cents, sale_unit_abbr: d.sale_unit_abbr };
      });
    const categories = flatCategories().filter((c) => c.name.toLowerCase().includes(q)).slice(0, 4).map((c) => ({ id: c.id, name: c.name, slug: c.slug, url_path: c.url_path }));
    return ok({ products, categories, brands: [] });
  }),
  http.get(api('/products'), ({ request }) => productList(new URL(request.url))),
  http.get(api('/products/:slug'), ({ params }) => {
    const seed = findSeed(String(params.slug));
    if (!seed) return err(404, { message: 'Produto não encontrado.', code: 'not_found', category: null });
    return ok(productDetail(seed) as unknown as JsonBodyType);
  }),
  http.get(api('/products/:slug/related'), ({ params }) => {
    const seed = findSeed(String(params.slug));
    return ok(productSeeds.filter((p) => p.slug !== seed?.slug).slice(0, 8).map(productCard) as unknown as JsonBodyType);
  }),
  http.post(api('/products/:slug/price-preview'), async ({ request, params }) => {
    const seed = findSeed(String(params.slug));
    if (!seed) return err(404, { message: 'Produto não encontrado.', code: 'not_found' });
    const body = (await request.json()) as LineInputBody;
    if (!seed.variants.some((v) => v.id === body.variant_id)) return err(422, { message: 'Variante inválida.', errors: { variant_id: ['Variante inválida.'] } });
    const res = validateLine(body);
    if (!res.ok) return err(422, res.body);
    return ok(buildPreview(res.variant, res.valid) as unknown as JsonBodyType);
  }),
  http.post(api('/shipping/quote'), async ({ request }) => {
    const body = (await request.json()) as { postal_code?: string; items?: LineInputBody[] };
    const cep = (body.postal_code ?? '').replace(/\D/g, '');
    if (cep.length !== 8 || cep === '00000000') return err(422, { message: 'CEP inválido.', errors: { postal_code: ['CEP inválido.'] } });
    let subtotal = 0;
    let weight = 0;
    let pickupOnly = false;
    for (const [i, item] of (body.items ?? []).entries()) {
      const res = validateLine(item, `items.${i}.`);
      if (!res.ok) return err(422, res.body);
      const pv = buildPreview(res.variant, res.valid);
      subtotal += pv.line_total_cents;
      weight += pv.weight_grams;
      if (findSeed(res.productSlug)?.pickup_only) pickupOnly = true;
    }
    return ok({ quote_id: null, expires_at: null, destination: { postal_code: cep, city: cep.startsWith('890') ? 'Blumenau' : 'São Paulo', state: cep.startsWith('890') ? 'SC' : 'SP' }, options: shippingOptions(subtotal, pickupOnly), notice: null, message: null, total_weight_grams: weight } as unknown as JsonBodyType);
  }),
  http.get(api('/postal-codes/:cep'), ({ params }) => {
    const cep = String(params.cep).replace(/\D/g, '');
    if (cep.length !== 8 || cep === '00000000') return err(422, { message: 'CEP inválido.', errors: { cep: ['CEP inválido.'] } });
    if (cep === '99999999') return err(404, { message: 'CEP não encontrado.', code: 'not_found' });
    const bnu = cep.startsWith('890');
    return ok({ postal_code: cep, street: bnu ? 'Rua das Palmeiras' : 'Avenida Paulista', district: bnu ? 'Victor Konder' : 'Bela Vista', city: bnu ? 'Blumenau' : 'São Paulo', state: bnu ? 'SC' : 'SP', city_ibge_code: bnu ? '4202404' : '3550308', source: 'viacep' });
  }),

  // ---------- carrinho ----------
  http.get(api('/cart'), ({ request }) => {
    const { cart, notFound } = currentCart(request, false);
    if (notFound) return err(404, { message: 'Carrinho não encontrado.', code: 'cart_not_found' });
    const url = new URL(request.url);
    const q = url.searchParams.get('shipping_quote_id');
    const o = url.searchParams.get('shipping_option_id');
    return ok(buildCart(cart ?? emptyCart(null), q && o ? { quoteId: q, optionId: o } : null) as unknown as JsonBodyType);
  }),
  http.post(api('/cart/items'), async ({ request }) => {
    const body = (await request.json()) as LineInputBody & Record<string, unknown>;
    for (const f of ['price', 'price_cents', 'unit_price_cents', 'line_total_cents', 'total_cents']) {
      if (f in body) return err(422, { message: `O campo ${f} não é permitido.`, errors: { [f]: [`O campo ${f} não é permitido.`] } });
    }
    const res = validateLine(body);
    if (!res.ok) return err(422, res.body);
    const { cart, created, notFound } = currentCart(request, true);
    if (notFound || !cart) return err(404, { message: 'Carrinho não encontrado.', code: 'cart_not_found' });
    const vb = res.valid.body;
    const existing = cart.lines.find((l) => l.variant_id === body.variant_id && lineKey(l.body) === lineKey(vb));
    const sumStock = cart.lines.filter((l) => l.variant_id === body.variant_id).reduce((s, l) => { const r = validateLine({ variant_id: l.variant_id, ...l.body }); return s + (r.ok ? r.valid.stockMilli : 0); }, 0) + res.valid.stockMilli;
    if (sumStock > toMilli(stock.get(res.variant.id) ?? 0)) return stockError(res.variant.id, sumStock);
    if (existing) {
      if ('quantity' in existing.body && 'quantity' in vb) existing.body = { quantity: milliToNumber(toMilli(existing.body.quantity) + toMilli(vb.quantity)) };
      else if ('pieces' in existing.body && 'pieces' in vb) existing.body = { ...existing.body, pieces: existing.body.pieces + vb.pieces };
      return cartResponse(cart, 200, created);
    }
    const unit = buildPreview(res.variant, res.valid).unit_price_cents;
    cart.lines.push({ id: mockState.nextLineId++, variant_id: res.variant.id, body: vb, lastSeenUnitPrice: unit });
    cart.quotes.clear();
    return cartResponse(cart, 201, created);
  }),
  http.patch(api('/cart/items/:id'), async ({ request, params }) => {
    const { cart, notFound } = currentCart(request, false);
    if (notFound || !cart) return err(404, { message: 'Carrinho não encontrado.', code: 'cart_not_found' });
    const line = cart.lines.find((l) => l.id === Number(params.id));
    if (!line) return err(404, { message: 'Item não encontrado.', code: 'not_found' });
    const patch = (await request.json()) as Partial<{ quantity: number; width_m: number; height_m: number; pieces: number }>;
    const next = { ...line.body, ...patch } as LineBody;
    const res = validateLine({ variant_id: line.variant_id, ...next });
    if (!res.ok) return err(422, res.body);
    if (res.valid.stockMilli > toMilli(stock.get(line.variant_id) ?? 0)) return stockError(line.variant_id, res.valid.stockMilli);
    line.body = res.valid.body;
    cart.quotes.clear();
    return cartResponse(cart);
  }),
  http.delete(api('/cart/items/:id'), ({ request, params }) => {
    const { cart } = currentCart(request, false);
    if (!cart) return err(404, { message: 'Carrinho não encontrado.', code: 'cart_not_found' });
    cart.lines = cart.lines.filter((l) => l.id !== Number(params.id));
    cart.quotes.clear();
    return cartResponse(cart);
  }),
  http.delete(api('/cart'), ({ request }) => {
    const { cart } = currentCart(request, false);
    if (!cart) return ok(buildCart(emptyCart(null)) as unknown as JsonBodyType);
    cart.lines = [];
    cart.coupon = null;
    return cartResponse(cart);
  }),
  http.put(api('/cart/coupon'), async ({ request }) => {
    const { cart, created } = currentCart(request, true);
    if (!cart) return err(404, { message: 'Carrinho não encontrado.', code: 'cart_not_found' });
    const { code } = (await request.json()) as { code: string };
    const c = COUPONS[code?.toUpperCase()];
    if (!c) return err(422, { message: 'Cupom inválido ou expirado.', errors: { code: ['Cupom inválido ou expirado.'] } });
    const subtotal = buildCart(cart).totals.subtotal_cents;
    if (subtotal < c.min) return err(422, { message: 'Pedido mínimo para este cupom: R$ 100,00.', errors: { code: ['Pedido mínimo para este cupom: R$ 100,00.'] } });
    cart.coupon = code.toUpperCase();
    return cartResponse(cart, 200, created);
  }),
  http.delete(api('/cart/coupon'), ({ request }) => {
    const { cart } = currentCart(request, false);
    if (!cart) return ok(buildCart(emptyCart(null)) as unknown as JsonBodyType);
    cart.coupon = null;
    return cartResponse(cart);
  }),
  http.post(api('/cart/shipping-quote'), async ({ request }) => {
    const { cart } = currentCart(request, false);
    if (!cart || !cart.lines.length) return err(409, { message: 'Seu carrinho está vazio.', code: 'cart_empty' });
    const body = (await request.json()) as { postal_code?: string; address_uuid?: string };
    let cep = (body.postal_code ?? '').replace(/\D/g, '');
    if (body.address_uuid) {
      const a = mockState.addresses.find((x) => x.uuid === body.address_uuid);
      if (!a || !mockState.session) return err(422, { message: 'Endereço inválido.', errors: { address_uuid: ['Endereço inválido.'] } });
      cep = a.postal_code;
    }
    if (cep.length !== 8) return err(422, { message: 'CEP inválido.', errors: { postal_code: ['CEP inválido.'] } });
    return ok(quoteFor(cart, cep) as unknown as JsonBodyType);
  }),
  http.post(api('/cart/acknowledge-prices'), ({ request }) => {
    const { cart } = currentCart(request, false);
    if (!cart) return ok(buildCart(emptyCart(null)) as unknown as JsonBodyType);
    const built = buildCart(cart);
    cart.lines.forEach((l) => { l.lastSeenUnitPrice = built.items.find((i) => i.id === l.id)?.unit_price_cents ?? null; });
    return cartResponse(cart);
  }),

  // ---------- auth ----------
  http.post(api('/auth/login'), async ({ request }) => {
    const body = (await request.json()) as { email?: string; password?: string };
    if (body.email?.toLowerCase() !== demoCustomer.email || body.password !== DEMO_PASSWORD) {
      return err(422, { message: 'E-mail ou senha inválidos.', errors: { email: ['E-mail ou senha inválidos.'] } });
    }
    const token = request.headers.get('X-Cart-Token');
    const guest = token ? mockState.guestCarts.get(token) : undefined;
    let merge = null;
    if (guest?.lines.length) {
      mockState.customerCart.lines.push(...guest.lines);
      mockState.guestCarts.delete(token!);
      merge = { merged: true, lines_added: guest.lines.length, lines_combined: 0, adjustments: [], dropped: [], coupon: null };
    }
    mockState.session = structuredClone(demoCustomer);
    return ok({ customer: mockState.session, cart_merge: merge } as unknown as JsonBodyType);
  }),
  http.post(api('/auth/register'), async ({ request }) => {
    const body = (await request.json()) as { name: string; email: string; type: 'individual' | 'company'; cpf?: string | null; phone: string; company?: { cnpj: string; legal_name: string; trade_name: string | null; state_registration: string | null; state_registration_exempt: boolean } };
    if (body.email === demoCustomer.email) return err(422, { message: 'Já existe uma conta com este e-mail.', errors: { email: ['Já existe uma conta com este e-mail.'] } });
    mockState.session = { ...structuredClone(demoCustomer), uuid: uuid(), name: body.name, email: body.email, type: body.type, cpf: body.cpf ?? null, phone: body.phone, company: body.company ? { ...body.company, trade_name: body.company.trade_name ?? null } : null };
    return ok({ customer: mockState.session, cart_merge: null } as unknown as JsonBodyType, 201);
  }),
  http.post(api('/auth/logout'), () => { mockState.session = null; mockState.customerCart = emptyCart(null); return new HttpResponse(null, { status: 204 }); }),
  http.post(api('/auth/forgot-password'), () => ok({ message: 'Se o e-mail existir, enviaremos instruções.' })),
  http.post(api('/auth/reset-password'), async ({ request }) => {
    const b = (await request.json()) as { token: string };
    return b.token === 'expirado' ? err(422, { message: 'Este link expirou ou é inválido.', errors: { token: ['Este link expirou ou é inválido.'] } }) : ok({ message: 'Senha alterada.' });
  }),
  http.post(api('/auth/email/verify'), () => ok({ verified: true })),

  // ---------- /me ----------
  http.get(api('/me'), () => (mockState.session ? ok(mockState.session as unknown as JsonBodyType) : err(401, { message: 'Não autenticado.', code: 'unauthenticated' }))),
  http.patch(api('/me'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const b = (await request.json()) as Partial<Customer>;
    Object.assign(mockState.session!, { ...(b.name ? { name: b.name } : {}), ...(b.phone ? { phone: b.phone } : {}), ...(b.cpf && !mockState.session!.cpf ? { cpf: b.cpf } : {}), ...(typeof b.marketing_opt_in === 'boolean' ? { marketing_opt_in: b.marketing_opt_in } : {}) });
    mockState.session!.missing_fields = [];
    mockState.session!.profile_complete = true;
    return ok(mockState.session as unknown as JsonBodyType);
  }),
  http.put(api('/me/password'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const b = (await request.json()) as { current_password: string };
    return b.current_password !== DEMO_PASSWORD ? err(422, { message: 'Senha atual incorreta.', errors: { current_password: ['Senha atual incorreta.'] } }) : new HttpResponse(null, { status: 204 });
  }),
  http.patch(api('/me/company'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    if (!mockState.session!.company) return err(403, { message: 'Ação não permitida.', code: 'forbidden' });
    Object.assign(mockState.session!.company, await request.json());
    return ok(mockState.session as unknown as JsonBodyType);
  }),
  http.get(api('/me/addresses'), () => requireSession() ?? ok(mockState.addresses as unknown as JsonBodyType)),
  http.post(api('/me/addresses'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const b = (await request.json()) as Address;
    const cep = b.postal_code.replace(/\D/g, '');
    if (cep === '99999999') return err(422, { message: 'CEP não encontrado.', errors: { postal_code: ['CEP não encontrado.'] } });
    const bnu = cep.startsWith('890');
    const a: Address = { ...b, uuid: uuid(), city: bnu ? 'Blumenau' : 'São Paulo', state: bnu ? 'SC' : 'SP', city_ibge_code: null, is_default: b.is_default || mockState.addresses.length === 0, formatted: `${b.street}, ${b.number} – ${b.district} – ${bnu ? 'Blumenau/SC' : 'São Paulo/SP'} – ${formatCEP(cep)}`, created_at: new Date().toISOString(), postal_code: cep };
    if (a.is_default) mockState.addresses.forEach((x) => { x.is_default = false; });
    mockState.addresses.unshift(a);
    return ok(a as unknown as JsonBodyType, 201);
  }),
  http.patch(api('/me/addresses/:uuid'), async ({ request, params }) => {
    const a = mockState.addresses.find((x) => x.uuid === params.uuid);
    if (!a) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    Object.assign(a, await request.json());
    a.formatted = `${a.street}, ${a.number} – ${a.district} – ${a.city}/${a.state} – ${formatCEP(a.postal_code)}`;
    return ok(a as unknown as JsonBodyType);
  }),
  http.delete(api('/me/addresses/:uuid'), ({ params }) => {
    const wasDefault = mockState.addresses.find((x) => x.uuid === params.uuid)?.is_default;
    mockState.addresses = mockState.addresses.filter((x) => x.uuid !== params.uuid);
    if (wasDefault && mockState.addresses[0]) mockState.addresses[0].is_default = true;
    return new HttpResponse(null, { status: 204 });
  }),
  http.post(api('/me/addresses/:uuid/default'), ({ params }) => {
    mockState.addresses.forEach((x) => { x.is_default = x.uuid === params.uuid; });
    return ok(mockState.addresses.find((x) => x.uuid === params.uuid) as unknown as JsonBodyType);
  }),
  http.get(api('/me/orders'), ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const url = new URL(request.url);
    const status = url.searchParams.get('status')?.split(',');
    const perPage = Number(url.searchParams.get('per_page') ?? 10);
    const page = Number(url.searchParams.get('page') ?? 1);
    const list = mockState.orders.filter((o) => !status || status.includes(o.status)).map(summaryOf);
    const data = list.slice((page - 1) * perPage, page * perPage);
    const last = Math.max(1, Math.ceil(list.length / perPage));
    return HttpResponse.json({ data, links: { first: '', last: '', prev: null, next: null }, meta: { current_page: page, from: 1, last_page: last, path: '', per_page: perPage, to: data.length, total: list.length, links: [] } } as unknown as JsonBodyType);
  }),
  http.get(api('/me/orders/:uuid'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    return o ? ok(o as unknown as JsonBodyType) : err(404, { message: 'Não encontrado.', code: 'not_found' });
  }),
  http.get(api('/me/orders/:uuid/status'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    const poll: OrderStatusPoll = { uuid: o.uuid, number: o.number, status: o.status, payment_status: o.payment_status, expires_at: o.expires_at, paid_at: o.paid_at, payment: o.payment ? { uuid: o.payment.uuid, status: o.payment.status, expires_at: o.payment.expires_at, has_pix: Boolean(o.payment.pix) } : null, updated_at: new Date().toISOString() };
    return ok(poll as unknown as JsonBodyType);
  }),
  http.post(api('/me/orders/:uuid/cancel'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    if (o.status !== 'pending_payment') return err(409, { message: 'Este pedido não pode ser cancelado.', code: 'invalid_status_transition', allowed_transitions: [] });
    Object.assign(o, { status: 'cancelled', status_label: 'Cancelado', cancelled_at: new Date().toISOString(), cancel_reason_code: 'customer', cancel_reason_label: 'Cancelado pelo cliente', allowed_actions: { can_pay: false, can_retry_payment: false, can_cancel: false, can_request_cancellation: false, can_reorder: true } });
    o.timeline.push({ status: 'cancelled', status_label: 'Cancelado', occurred_at: new Date().toISOString(), note: null });
    return ok(o as unknown as JsonBodyType);
  }),
  http.post(api('/me/orders/:uuid/cancellation-request'), async ({ params, request }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    const b = (await request.json()) as { reason: string };
    o.cancellation_request = { requested_at: new Date().toISOString(), reason: b.reason };
    o.allowed_actions.can_request_cancellation = false;
    return ok(o as unknown as JsonBodyType);
  }),
  http.post(api('/me/orders/:uuid/reorder'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    const items = o.items.map((i) => {
      const seed = productSeeds.find((p) => p.variants.some((v) => v.sku === i.sku))!;
      const v = seed.variants.find((x) => x.sku === i.sku)!;
      const body = (i.sale_unit === 'SQUARE_METER' ? { width_m: i.configuration.width_m, height_m: i.configuration.height_m, pieces: i.configuration.pieces } : { quantity: i.configuration.quantity }) as LineBody;
      mockState.customerCart.lines.push({ id: mockState.nextLineId++, variant_id: v.id, body, lastSeenUnitPrice: null });
      return { sku: i.sku, product_name: i.product_name, result: 'added' as const, message: 'Adicionado', requested: i.configuration, added: i.configuration, previous_unit_price_cents: i.unit_price_cents, current_unit_price_cents: v.price, product_url_path: i.product_url_path };
    });
    return ok({ cart: buildCart(mockState.customerCart), summary: { total_items: items.length, added_items: items.length, adjusted_items: 0, skipped_items: 0 }, items } as unknown as JsonBodyType);
  }),
  http.post(api('/me/orders/:uuid/payment'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o || !o.payment) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    o.payment.pix = { qr_code_base64: PIX_QR_BASE64, copy_paste: `00020126580014BR.GOV.BCB.PIX${o.number}`, expires_at: o.expires_at! };
    return ok(o.payment as unknown as JsonBodyType);
  }),
  http.get(api('/me/reorder-suggestions'), () => {
    if (!mockState.session) return err(401, { message: 'Não autenticado.', code: 'unauthenticated' });
    const d = productDetail(productSeeds[0]);
    return ok([{ variant_id: 1, product: { id: d.id, slug: d.slug, name: d.name, url_path: d.url_path, image: d.images[0], sale_unit: d.sale_unit, sale_unit_abbr: d.sale_unit_abbr }, variant: { id: 1, sku: 'VIN-BR-122-BR', name: 'Brilho' }, last_configuration: { quantity: 10, width_m: null, height_m: null, pieces: null }, last_configuration_label: '10 m', last_ordered_at: '2026-09-12T13:00:00Z', current_unit_price_cents: 1590, price_source: 'base', availability: { status: 'in_stock' } }]);
  }),
  http.post(api('/dev/payments/:uuid/approve'), ({ params }) => {
    const o = mockState.orders.find((x) => x.uuid === params.uuid);
    if (!o) return err(404, { message: 'Não encontrado.', code: 'not_found' });
    const now = new Date().toISOString();
    Object.assign(o, { status: 'paid', status_label: 'Pago', payment_status: 'approved', paid_at: now });
    if (o.payment) Object.assign(o.payment, { status: 'approved', paid_at: now });
    o.timeline.push({ status: 'paid', status_label: 'Pagamento aprovado', occurred_at: now, note: null });
    return ok({ status: 'dispatched', webhook_event_external_id: uuid() }, 202);
  }),

  // ---------- checkout ----------
  http.post(api('/checkout/preview'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const b = (await request.json()) as { address_uuid: string; shipping_quote_id?: string; shipping_option_id?: string };
    const s = checkoutSummary(b.address_uuid, b.shipping_quote_id ?? null, b.shipping_option_id ?? null);
    return 'error' in s ? s.error : ok(s as unknown as JsonBodyType);
  }),
  http.post(api('/checkout'), async ({ request }) => {
    const denied = requireSession(); if (denied) return denied;
    const key = request.headers.get('Idempotency-Key');
    if (!key) return err(422, { message: 'Idempotency-Key obrigatório.', errors: { idempotency_key: ['Idempotency-Key obrigatório.'] } });
    const b = (await request.json()) as Record<string, unknown> & { address_uuid: string; shipping_quote_id: string; shipping_option_id: string; expected_total_cents: number; notes: string | null };
    for (const f of ['total_cents', 'shipping_cents', 'discount_cents', 'customer_id', 'status', 'items', 'price']) {
      if (f in b) return err(422, { message: `O campo ${f} não é permitido.`, errors: { [f]: [`O campo ${f} não é permitido.`] } });
    }
    const fp = JSON.stringify([b.address_uuid, b.shipping_quote_id, b.shipping_option_id, b.expected_total_cents, b.notes]);
    const prior = mockState.idempotency.get(key);
    if (prior) {
      const order = mockState.orders.find((o) => o.uuid === prior.uuid)!;
      if (prior.fingerprint !== fp) return err(409, { message: 'Esta chave de idempotência já foi usada com outros dados.', code: 'idempotency_conflict', order: { uuid: order.uuid, number: order.number } });
      return ok({ order, payment: order.payment, replayed: true } as unknown as JsonBodyType, 200);
    }
    const summary = checkoutSummary(b.address_uuid, b.shipping_quote_id, b.shipping_option_id);
    if ('error' in summary) return summary.error;
    if (!summary.items.length) return err(409, { message: 'Seu carrinho está vazio.', code: 'cart_empty' });
    if (summary.totals.total_cents !== b.expected_total_cents) return err(409, { message: 'Os valores do pedido mudaram. Revise e confirme novamente.', code: 'price_changed', summary });
    mockState.orderSeq += 1;
    const number = `CV-${String(mockState.orderSeq).padStart(6, '0')}`;
    const now = new Date();
    const expires = new Date(now.getTime() + 30 * 60_000).toISOString();
    const opt = summary.shipping_option!;
    const payment = { uuid: uuid(), method: 'pix' as const, status: 'pending' as const, amount_cents: summary.totals.total_cents, expires_at: expires, paid_at: null, pix: { qr_code_base64: PIX_QR_BASE64, copy_paste: `00020126580014BR.GOV.BCB.PIX${number}`, expires_at: expires } };
    const order: OrderDetail = {
      uuid: uuid(), number, status: 'pending_payment', status_label: 'Aguardando pagamento', payment_status: 'pending', payment_method: 'pix', placed_at: now.toISOString(), expires_at: expires,
      items_count: summary.items.length, total_cents: summary.totals.total_cents, shipping_method_name: opt.name, shipping_method_type: opt.method_type, tracking_code: null,
      allowed_actions: { can_pay: true, can_retry_payment: false, can_cancel: true, can_request_cancellation: false, can_reorder: true },
      paid_at: null, cancelled_at: null, cancel_reason_code: null, cancel_reason_label: null, cancellation_request: null,
      items: summary.items.map((i) => ({ product_id: i.product.id, product_name: i.product.name, variant_name: i.variant.name, sku: i.variant.sku, product_url_path: i.product.url_path, sale_unit: i.sale_unit, sale_unit_abbr: i.sale_unit_abbr, configuration: i.configuration, configuration_label: i.configuration_label, billable_quantity: i.billable_quantity ?? 0, stock_quantity: i.stock_quantity ?? 0, area_m2: i.area_m2, min_area_applied: i.min_area_applied, unit_price_cents: i.unit_price_cents ?? 0, base_unit_price_cents: i.base_unit_price_cents ?? 0, price_source: i.price_source ?? 'base', subtotal_cents: i.line_total_cents ?? 0, discount_cents: 0, total_cents: i.line_total_cents ?? 0, weight_grams: i.weight_grams })),
      totals: { subtotal_cents: summary.totals.subtotal_cents, discount_cents: summary.totals.discount_cents, shipping_cents: summary.totals.shipping_cents ?? 0, shipping_discount_cents: summary.totals.shipping_discount_cents, total_cents: summary.totals.total_cents },
      coupon_code: summary.coupon?.code ?? null, total_weight_grams: summary.total_weight_grams, billing: summary.billing,
      shipping: { method_name: opt.name, method_type: opt.method_type, carrier_code: null, service_code: null, delivery_days_min: opt.delivery_days_min, delivery_days_max: opt.delivery_days_max, delivery_label: opt.delivery_label, estimated_delivery_date: null, tracking_code: null, tracking_url: null, address: opt.method_type === 'pickup' ? null : { ...summary.address }, pickup_address: opt.pickup_address, picked_up_at: null, picked_up_by_name: null },
      payment, timeline: [{ status: 'pending_payment', status_label: 'Pedido realizado', occurred_at: now.toISOString(), note: null }], notes: b.notes,
    };
    mockState.orders.unshift(order);
    mockState.idempotency.set(key, { fingerprint: fp, uuid: order.uuid });
    mockState.customerCart = emptyCart(null);
    return ok({ order, payment, replayed: false } as unknown as JsonBodyType, 201);
  }),
];
