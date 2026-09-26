import { http } from 'msw';
import type { AdminCategory, AdminProduct, AdminProductListItem, AdminVariant, AdminVariantPickerItem, InventoryItem, InventoryMovement } from '@/shared/api/types';
import { SALE_UNIT_ABBR } from '@/shared/formatters/quantity';
import { db, nextId } from '../db';
import { makeVariant } from '../seed';
import { B, conflict, invalid, noContent, notFound, now, ok, paginate, sortBy, textMatch } from '../util';

const flatCats = (list: AdminCategory[]): AdminCategory[] => list.flatMap((c) => [c, ...flatCats(c.children)]);
const catRef = (id: number) => {
  const c = flatCats(db.categories).find((x) => x.id === id);
  return { id, name: c?.name ?? '—', slug: c?.slug ?? '', url_path: `/${c?.slug ?? ''}` };
};

function listItem(p: AdminProduct): AdminProductListItem {
  const brand = db.brands.find((b) => b.id === p.brand_id);
  const active = p.variants.filter((v) => v.is_active && !v.deleted_at);
  return {
    id: p.id, name: p.name, slug: p.slug, sale_unit: p.sale_unit, primary_category: catRef(p.primary_category_id),
    brand: brand ? { id: brand.id, name: brand.name, slug: brand.slug } : null, image: p.images[0] ?? null, variants_count: active.length,
    skus: active.slice(0, 5).map((v) => v.sku), min_price_cents: Math.min(...active.map((v) => v.price_cents), Infinity) === Infinity ? 0 : Math.min(...active.map((v) => v.price_cents)),
    total_available: active.reduce((s, v) => s + v.inventory.available, 0), has_low_stock: active.some((v) => v.inventory.is_low_stock),
    is_active: p.is_active, is_featured: p.is_featured, pickup_only: p.pickup_only, updated_at: p.updated_at, deleted_at: p.deleted_at,
  };
}

export function allVariants(): { product: AdminProduct; variant: AdminVariant }[] {
  return db.products.filter((p) => !p.deleted_at).flatMap((product) => product.variants.filter((v) => !v.deleted_at).map((variant) => ({ product, variant })));
}

export function inventoryItem(product: AdminProduct, v: AdminVariant): InventoryItem {
  return {
    variant_id: v.id, sku: v.sku, variant_name: v.name, product: { id: product.id, name: product.name, slug: product.slug, is_active: product.is_active },
    sale_unit: product.sale_unit, stock_unit_abbr: SALE_UNIT_ABBR[product.sale_unit], on_hand: v.inventory.on_hand, reserved: v.inventory.reserved,
    available: v.inventory.available, low_stock_threshold: v.inventory.low_stock_threshold, low_stock_threshold_override: null,
    is_low_stock: v.inventory.is_low_stock, low_stock_alerted_at: null, updated_at: v.updated_at,
  };
}

const findVariant = (id: number) => allVariants().find((x) => x.variant.id === id);
const round3 = (n: number) => Math.round(n * 1000) / 1000;

function applyStock(v: AdminVariant, onHand: number) {
  v.inventory.on_hand = round3(onHand);
  v.inventory.available = round3(onHand - v.inventory.reserved);
  v.inventory.is_low_stock = v.inventory.available <= v.inventory.low_stock_threshold;
  v.updated_at = now();
}

export const catalogHandlers = [
  // Categorias
  http.get(`${B}/admin/categories`, () => ok(db.categories)),
  http.post(`${B}/admin/categories`, async ({ request }) => {
    const b = (await request.json()) as Partial<AdminCategory>;
    if (!b.name || b.name.length < 2) return invalid({ name: ['Informe o nome (mín. 2 caracteres).'] });
    const c: AdminCategory = { id: nextId(), parent_id: b.parent_id ?? null, name: b.name, slug: b.slug || b.name.toLowerCase().replace(/\s+/g, '-'), description_html: b.description_html ?? null, image_url: null, meta_title: b.meta_title ?? null, meta_description: b.meta_description ?? null, position: 99, is_active: b.is_active ?? true, depth: 1, products_count: 0, children: [], created_at: now(), updated_at: now(), deleted_at: null };
    const parent = flatCats(db.categories).find((x) => x.id === c.parent_id);
    if (parent) {
      c.depth = (parent.depth + 1) as 1 | 2 | 3;
      parent.children.push(c);
    } else db.categories.push(c);
    return ok(c, 201);
  }),
  http.patch(`${B}/admin/categories/:id`, async ({ params, request }) => {
    const c = flatCats(db.categories).find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    Object.assign(c, await request.json(), { updated_at: now() });
    return ok(c);
  }),
  http.delete(`${B}/admin/categories/:id`, ({ params }) => {
    const c = flatCats(db.categories).find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    if (c.children.length) return conflict('resource_in_use', 'A categoria tem subcategorias ativas.', { blockers: c.children.map((x) => ({ type: 'category', id: x.id, label: x.name })) });
    const prune = (list: AdminCategory[]): AdminCategory[] => list.filter((x) => x.id !== c.id).map((x) => ({ ...x, children: prune(x.children) }));
    db.categories = prune(db.categories);
    return noContent();
  }),
  http.post(`${B}/admin/categories/reorder`, () => noContent()),
  // Marcas
  http.get(`${B}/admin/brands`, ({ request }) => {
    const url = new URL(request.url);
    const q = url.searchParams.get('q');
    const rows = sortBy(db.brands.filter((b) => !b.deleted_at && textMatch(q, b.name)), url.searchParams.get('sort'), { name: (b) => b.name, created_at: (b) => b.created_at });
    return Response.json(paginate(rows, url));
  }),
  http.post(`${B}/admin/brands`, async ({ request }) => {
    const b = (await request.json()) as { name: string; slug?: string; is_active?: boolean };
    if (db.brands.some((x) => x.name === b.name)) return invalid({ name: ['Já existe uma marca com este nome.'] });
    const brand = { id: nextId(), name: b.name, slug: b.slug || b.name.toLowerCase(), logo_url: null, is_active: b.is_active ?? true, products_count: 0, created_at: now(), updated_at: now(), deleted_at: null };
    db.brands.push(brand);
    return ok(brand, 201);
  }),
  http.patch(`${B}/admin/brands/:id`, async ({ params, request }) => {
    const b = db.brands.find((x) => x.id === Number(params.id));
    if (!b) return notFound();
    Object.assign(b, await request.json(), { updated_at: now() });
    return ok(b);
  }),
  http.delete(`${B}/admin/brands/:id`, ({ params }) => {
    db.brands = db.brands.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  // Produtos
  http.get(`${B}/admin/products/slug-availability`, ({ request }) => {
    const url = new URL(request.url);
    const slug = url.searchParams.get('slug');
    const ignore = Number(url.searchParams.get('ignore_id'));
    const taken = db.products.some((p) => p.slug === slug && p.id !== ignore);
    return ok({ available: !taken, suggestion: taken ? `${slug}-2` : null });
  }),
  http.get(`${B}/admin/products`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    const q = sp.get('q');
    let rows = db.products.filter((p) => (sp.get('include_deleted') === '1' || !p.deleted_at) && textMatch(q, p.name, ...p.variants.map((v) => v.sku)));
    const status = sp.get('status');
    if (status === 'active') rows = rows.filter((p) => p.is_active);
    if (status === 'inactive') rows = rows.filter((p) => !p.is_active);
    if (sp.get('sale_unit')) rows = rows.filter((p) => p.sale_unit === sp.get('sale_unit'));
    if (sp.get('category_id')) rows = rows.filter((p) => p.category_ids.includes(Number(sp.get('category_id'))));
    if (sp.get('brand_id')) rows = rows.filter((p) => p.brand_id === Number(sp.get('brand_id')));
    if (sp.get('low_stock') === '1') rows = rows.filter((p) => p.variants.some((v) => v.inventory.is_low_stock));
    const items = sortBy(rows.map(listItem), sp.get('sort'), { name: (p) => p.name, updated_at: (p) => p.updated_at, created_at: (p) => p.id, min_price: (p) => p.min_price_cents });
    return Response.json(paginate(items, url));
  }),
  http.get(`${B}/admin/products/:id`, ({ params }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    return p ? ok(p) : notFound();
  }),
  http.post(`${B}/admin/products/bulk`, async ({ request }) => {
    const b = (await request.json()) as { ids: number[]; action: string };
    for (const p of db.products.filter((x) => b.ids.includes(x.id))) {
      if (b.action === 'activate') p.is_active = true;
      if (b.action === 'deactivate') p.is_active = false;
      if (b.action === 'delete') p.deleted_at = now();
    }
    return ok({ succeeded: b.ids, failed: [] });
  }),
  http.post(`${B}/admin/products`, async ({ request }) => {
    const b = (await request.json()) as Omit<Partial<AdminProduct>, 'variants'> & { variants: (Partial<AdminVariant> & { initial_stock?: number })[] };
    if (!b.name || b.name.length < 3) return invalid({ name: ['O nome deve ter entre 3 e 150 caracteres.'] });
    const errs: Record<string, string[]> = {};
    b.variants?.forEach((v, i) => {
      if (allVariants().some((x) => x.variant.sku === v.sku)) errs[`variants.${i}.sku`] = [`SKU já usado em outro produto.`];
    });
    if (Object.keys(errs).length) return invalid(errs);
    const id = nextId();
    const p: AdminProduct = {
      ...(b as AdminProduct), id, slug: b.slug || `produto-${id}`, url_path: `/produto-${id}`, sale_unit_locked: false, activation_issues: [], images: [],
      category_ids: Array.from(new Set([b.primary_category_id!, ...(b.category_ids ?? [])])), created_at: now(), updated_at: now(), deleted_at: null,
      variants: (b.variants ?? []).map((v, i) => {
        const stock = Number(v.initial_stock ?? 0);
        const { initial_stock: _ignored, ...rest } = v;
        return makeVariant(nextId(), { ...rest, position: i, inventory: { on_hand: stock, reserved: 0, available: stock, low_stock_threshold: 10, is_low_stock: stock <= 10 } });
      }),
    };
    db.products.push(p);
    return ok(p, 201);
  }),
  http.patch(`${B}/admin/products/:id`, async ({ params, request }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    const b = (await request.json()) as Omit<Partial<AdminProduct>, 'variants'> & { variants?: (Partial<AdminVariant> & { initial_stock?: number })[]; expected_updated_at?: string };
    if (b.expected_updated_at && b.expected_updated_at !== p.updated_at) return conflict('stale_resource', 'Este registro foi alterado por outra pessoa.', { current_updated_at: p.updated_at });
    if (b.sale_unit && b.sale_unit !== p.sale_unit && p.sale_unit_locked) return invalid({ sale_unit: ['A unidade de venda não pode ser alterada: já existem pedidos com este produto.'] });
    const { variants, expected_updated_at: _e, ...rest } = b;
    Object.assign(p, rest, { updated_at: now() });
    variants?.forEach((v, i) => {
      const existing = v.id ? p.variants.find((x) => x.id === v.id) : undefined;
      if (existing) Object.assign(existing, v, { updated_at: now() });
      else {
        const stock = Number(v.initial_stock ?? 0);
        const { initial_stock: _s, ...vr } = v;
        p.variants.push(makeVariant(nextId(), { ...vr, position: i, inventory: { on_hand: stock, reserved: 0, available: stock, low_stock_threshold: 10, is_low_stock: stock <= 10 } }));
      }
    });
    return ok(p);
  }),
  http.delete(`${B}/admin/products/:id/variants/:vid`, ({ params }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    const active = p.variants.filter((v) => !v.deleted_at);
    if (active.length <= 1 && p.is_active) return conflict('resource_in_use', 'Não é possível excluir a última variante ativa de um produto ativo.', { blockers: [] });
    const v = p.variants.find((x) => x.id === Number(params.vid));
    if (v) v.deleted_at = now();
    p.variants = p.variants.filter((x) => !x.deleted_at);
    return noContent();
  }),
  http.delete(`${B}/admin/products/:id`, ({ params }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (p) p.deleted_at = now();
    return noContent();
  }),
  // Imagens
  http.post(`${B}/admin/products/:id/images`, async ({ params, request }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    if (p.images.length >= 10) return invalid({ file: ['Máximo de 10 imagens por produto.'] });
    const form = await request.formData();
    const file = form.get('file');
    const name = file && typeof file === 'object' && 'name' in file ? String((file as File).name) : 'imagem.jpg';
    const img = { id: nextId(), urls: null, alt: String(form.get('alt') || p.name), width: null, height: null, position: p.images.length, variant_id: null, original_filename: name, created_at: now() };
    p.images.push(img);
    return ok(img, 201);
  }),
  http.patch(`${B}/admin/products/:id/images/:imageId`, async ({ params, request }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    const img = p?.images.find((i) => i.id === Number(params.imageId));
    if (!img) return notFound();
    Object.assign(img, await request.json());
    return ok(img);
  }),
  http.delete(`${B}/admin/products/:id/images/:imageId`, ({ params }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (p) p.images = p.images.filter((i) => i.id !== Number(params.imageId)).map((i, idx) => ({ ...i, position: idx }));
    return noContent();
  }),
  http.post(`${B}/admin/products/:id/images/reorder`, async ({ params, request }) => {
    const p = db.products.find((x) => x.id === Number(params.id));
    if (!p) return notFound();
    const { ids } = (await request.json()) as { ids: number[] };
    p.images = ids.map((id, idx) => ({ ...p.images.find((i) => i.id === id)!, position: idx }));
    return ok(p.images);
  }),
  // Variantes
  http.get(`${B}/admin/variants/sku-availability`, ({ request }) => {
    const url = new URL(request.url);
    const sku = (url.searchParams.get('sku') ?? '').toUpperCase();
    const ignore = Number(url.searchParams.get('ignore_id'));
    const used = allVariants().find((x) => x.variant.sku === sku && x.variant.id !== ignore);
    return ok({ available: !used, used_by: used ? { product_id: used.product.id, product_name: used.product.name } : null });
  }),
  http.get(`${B}/admin/variants`, ({ request }) => {
    const url = new URL(request.url);
    const q = url.searchParams.get('q');
    const rows: AdminVariantPickerItem[] = allVariants()
      .filter(({ product, variant }) => textMatch(q, variant.sku, variant.name, product.name))
      .map(({ product, variant }) => ({ id: variant.id, sku: variant.sku, name: variant.name, sale_unit: product.sale_unit, price_cents: variant.price_cents, is_active: variant.is_active, product: { id: product.id, name: product.name } }));
    return Response.json(paginate(rows, url));
  }),
  // Estoque
  http.get(`${B}/admin/inventory`, ({ request }) => {
    const url = new URL(request.url);
    const sp = url.searchParams;
    let rows = allVariants().filter(({ product, variant }) => textMatch(sp.get('q'), variant.sku, variant.name, product.name));
    if (sp.get('low_stock') === '1') rows = rows.filter((x) => x.variant.inventory.is_low_stock);
    if (sp.get('sale_unit')) rows = rows.filter((x) => x.product.sale_unit === sp.get('sale_unit'));
    const items = sortBy(rows.map((x) => inventoryItem(x.product, x.variant)), sp.get('sort'), { sku: (i) => i.sku, available: (i) => i.available, updated_at: (i) => i.updated_at ?? '' });
    return Response.json(paginate(items, url));
  }),
  http.get(`${B}/admin/inventory/movements`, ({ request }) => Response.json(paginate([...db.movements].reverse(), new URL(request.url)))),
  http.get(`${B}/admin/inventory/:variantId/movements`, ({ params, request }) => {
    const rows = db.movements.filter((m) => m.variant_id === Number(params.variantId)).reverse();
    return Response.json(paginate(rows, new URL(request.url)));
  }),
  http.patch(`${B}/admin/inventory/:variantId`, async ({ params, request }) => {
    const x = findVariant(Number(params.variantId));
    if (!x) return notFound();
    const b = (await request.json()) as { low_stock_threshold: number | null };
    x.variant.inventory.low_stock_threshold = b.low_stock_threshold ?? 10;
    applyStock(x.variant, x.variant.inventory.on_hand);
    return ok(inventoryItem(x.product, x.variant));
  }),
  http.post(`${B}/admin/inventory/:variantId/entries`, async ({ params, request }) => {
    const x = findVariant(Number(params.variantId));
    if (!x) return notFound();
    const b = (await request.json()) as { quantity: number; reason: string };
    if (!b.reason || b.reason.length < 3) return invalid({ reason: ['Informe o motivo (mín. 3 caracteres).'] });
    if (!(Number(b.quantity) > 0)) return invalid({ quantity: ['A quantidade deve ser maior que zero.'] });
    applyStock(x.variant, x.variant.inventory.on_hand + Number(b.quantity));
    const m: InventoryMovement = { id: nextId(), variant_id: x.variant.id, type: 'in', quantity: Number(b.quantity), on_hand_delta: Number(b.quantity), reserved_delta: 0, on_hand_after: x.variant.inventory.on_hand, reserved_after: x.variant.inventory.reserved, reason: b.reason, reference: null, actor: { type: 'admin', id: db.me.user.id, name: db.me.user.name }, created_at: now() };
    db.movements.push(m);
    return ok({ movement: m, inventory: inventoryItem(x.product, x.variant) }, 201);
  }),
  http.post(`${B}/admin/inventory/:variantId/adjustments`, async ({ params, request }) => {
    const x = findVariant(Number(params.variantId));
    if (!x) return notFound();
    const b = (await request.json()) as { new_on_hand: number; reason: string; expected_on_hand?: number };
    const inv = x.variant.inventory;
    if (b.expected_on_hand !== undefined && Number(b.expected_on_hand) !== inv.on_hand) return conflict('stale_resource', 'O estoque mudou enquanto você editava.', { current_updated_at: x.variant.updated_at });
    if (!b.reason || b.reason.length < 3) return invalid({ reason: ['Informe o motivo (mín. 3 caracteres).'] });
    if (Number(b.new_on_hand) < inv.reserved) return invalid({ new_on_hand: [`Existem ${inv.reserved} reservados em pedidos pendentes.`] });
    if (Number(b.new_on_hand) === inv.on_hand) return invalid({ new_on_hand: ['O novo valor é igual ao atual.'] });
    const delta = round3(Number(b.new_on_hand) - inv.on_hand);
    applyStock(x.variant, Number(b.new_on_hand));
    const m: InventoryMovement = { id: nextId(), variant_id: x.variant.id, type: 'adjust', quantity: Math.abs(delta), on_hand_delta: delta, reserved_delta: 0, on_hand_after: inv.on_hand, reserved_after: inv.reserved, reason: b.reason, reference: null, actor: { type: 'admin', id: db.me.user.id, name: db.me.user.name }, created_at: now() };
    db.movements.push(m);
    return ok({ movement: m, inventory: inventoryItem(x.product, x.variant) }, 201);
  }),
];
