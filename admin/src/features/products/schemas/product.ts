import { z } from 'zod';
import type { AdminProduct, AdminVariant, SaleUnit } from '@/shared/api/types';
import { isoToLocalInput, localInputToIso } from '@/shared/formatters/date';
import { decimalToInput, toMilli } from '@/shared/formatters/quantity';

/** Espelho das regras de API §3.G.5 (produto + VariantInput). */
import { RESERVED_SLUGS, SLUG_RE } from '@/shared/lib/validation';

export { RESERVED_SLUGS, SLUG_RE };
export const SKU_RE = /^[A-Z0-9-]{3,40}$/;
export const INTEGER_UNITS: SaleUnit[] = ['UNIT', 'ROLL', 'BOX', 'SQUARE_METER'];
export const SALE_UNITS: SaleUnit[] = ['UNIT', 'LINEAR_METER', 'SQUARE_METER', 'ROLL', 'KG', 'BOX'];

const dec = z.string(); // "" | decimal normalizado ("1.25"), validado no superRefine
const cm = z.string();

export const variantSchema = z.object({
  id: z.number().nullable(),
  sku: z.string().trim(),
  gtin: z.string().trim(),
  name: z.string().trim(),
  attributes: z.string(),
  price_cents: z.number().int().nullable(),
  promo_price_cents: z.number().int().nullable(),
  promo_starts_at: z.string(),
  promo_ends_at: z.string(),
  cost_cents: z.number().int().nullable(),
  weight_grams: z.number().int().nullable(),
  package_length_cm: cm,
  package_width_cm: cm,
  package_height_cm: cm,
  roll_length_m: dec,
  units_per_box: z.number().int().nullable(),
  units_per_package: z.number().int().nullable(),
  fixed_width_m: dec,
  is_active: z.boolean(),
  initial_stock: dec,
});
export type VariantForm = z.infer<typeof variantSchema>;

const base = z.object({
  name: z.string().trim(),
  slug: z.string().trim(),
  short_description: z.string(),
  description_html: z.string(),
  brand_id: z.number().nullable(),
  primary_category_id: z.number().nullable(),
  category_ids: z.array(z.number()),
  specifications: z.array(z.object({ label: z.string().trim(), value: z.string().trim() })),
  sale_unit: z.enum(['UNIT', 'LINEAR_METER', 'SQUARE_METER', 'ROLL', 'KG', 'BOX']),
  min_quantity: dec,
  max_quantity: dec,
  quantity_step: dec,
  width_mode: z.enum(['fixed', 'variable']),
  fixed_width_m: dec,
  min_width_m: dec,
  max_width_m: dec,
  min_height_m: dec,
  max_height_m: dec,
  min_billable_area_m2: dec,
  meta_title: z.string(),
  meta_description: z.string(),
  is_active: z.boolean(),
  is_featured: z.boolean(),
  pickup_only: z.boolean(),
  variants: z.array(variantSchema),
});
export type ProductForm = z.infer<typeof base>;

type Ctx = z.RefinementCtx;
const issue = (ctx: Ctx, path: (string | number)[], message: string) => ctx.addIssue({ code: 'custom', path, message });

function checkPositive(ctx: Ctx, path: (string | number)[], v: string, opts: { required?: boolean; integer?: boolean; max?: number; label: string }) {
  if (v === '') {
    if (opts.required) issue(ctx, path, `Informe ${opts.label}.`);
    return null;
  }
  const milli = toMilli(v);
  if (milli === null) return issue(ctx, path, 'Valor inválido.'), null;
  if (milli <= 0) return issue(ctx, path, 'Deve ser maior que zero.'), null;
  if (opts.integer && milli % 1000 !== 0) return issue(ctx, path, 'Use um número inteiro.'), null;
  if (opts.max !== undefined && milli > opts.max * 1000) return issue(ctx, path, `Máximo ${opts.max}.`), null;
  return milli;
}

function checkCm(ctx: Ctx, path: (string | number)[], v: string) {
  if (v === '') return;
  const m = toMilli(v);
  if (m === null || m <= 0) issue(ctx, path, 'Deve ser maior que zero.');
  else if (m % 100 !== 0) issue(ctx, path, 'Use no máximo 1 casa decimal.');
}

export const productSchema = base.superRefine((v, ctx) => {
  const u = v.sale_unit;
  const intUnit = INTEGER_UNITS.includes(u);
  if (v.name.length < 3 || v.name.length > 150) issue(ctx, ['name'], 'O nome deve ter entre 3 e 150 caracteres.');
  if (v.slug) {
    if (!SLUG_RE.test(v.slug)) issue(ctx, ['slug'], 'Use letras minúsculas, números e hífens (ex.: vinil-adesivo-branco).');
    else if (v.slug.length > 220) issue(ctx, ['slug'], 'No máximo 220 caracteres.');
    else if (RESERVED_SLUGS.includes(v.slug)) issue(ctx, ['slug'], 'Este endereço é reservado pelo sistema.');
  }
  if (v.short_description.length > 500) issue(ctx, ['short_description'], 'No máximo 500 caracteres.');
  if (v.description_html.length > 20000) issue(ctx, ['description_html'], 'No máximo 20.000 caracteres.');
  if (v.primary_category_id === null) issue(ctx, ['primary_category_id'], 'Selecione a categoria principal.');
  if (v.specifications.length > 30) issue(ctx, ['specifications'], 'No máximo 30 especificações.');
  v.specifications.forEach((s, i) => {
    if (s.label.length < 1 || s.label.length > 60) issue(ctx, ['specifications', i, 'label'], 'Rótulo: 1 a 60 caracteres.');
    if (s.value.length < 1 || s.value.length > 200) issue(ctx, ['specifications', i, 'value'], 'Valor: 1 a 200 caracteres.');
  });
  if (v.meta_title.length > 120) issue(ctx, ['meta_title'], 'No máximo 120 caracteres.');
  if (v.meta_description.length > 320) issue(ctx, ['meta_description'], 'No máximo 320 caracteres.');

  // Unidade de venda: quantidades (SQUARE_METER = peças, ADR-019)
  const step = checkPositive(ctx, ['quantity_step'], v.quantity_step, { required: true, integer: intUnit, label: 'o passo' });
  const min = checkPositive(ctx, ['min_quantity'], v.min_quantity, { required: true, integer: intUnit, label: 'a quantidade mínima' });
  const max = checkPositive(ctx, ['max_quantity'], v.max_quantity, { integer: intUnit, label: 'a quantidade máxima' });
  if (step && min && min % step !== 0) issue(ctx, ['min_quantity'], 'A quantidade mínima deve ser múltiplo do passo.');
  if (min && max) {
    if (max < min) issue(ctx, ['max_quantity'], 'A máxima deve ser maior ou igual à mínima.');
    else if (step && max % step !== 0) issue(ctx, ['max_quantity'], 'A quantidade máxima deve ser múltiplo do passo.');
  }
  if (u === 'SQUARE_METER') {
    if (v.width_mode === 'fixed') checkPositive(ctx, ['fixed_width_m'], v.fixed_width_m, { required: true, max: 100, label: 'a largura' });
    else {
      const a = checkPositive(ctx, ['min_width_m'], v.min_width_m, { required: true, max: 100, label: 'a largura mínima' });
      const b = checkPositive(ctx, ['max_width_m'], v.max_width_m, { required: true, max: 100, label: 'a largura máxima' });
      if (a && b && b < a) issue(ctx, ['max_width_m'], 'A largura máxima deve ser ≥ mínima.');
    }
    const h1 = checkPositive(ctx, ['min_height_m'], v.min_height_m, { required: true, max: 100, label: 'a altura mínima' });
    const h2 = checkPositive(ctx, ['max_height_m'], v.max_height_m, { required: true, max: 100, label: 'a altura máxima' });
    if (h1 && h2 && h2 < h1) issue(ctx, ['max_height_m'], 'A altura máxima deve ser ≥ mínima.');
    checkPositive(ctx, ['min_billable_area_m2'], v.min_billable_area_m2, { label: 'a área mínima' });
  }
  if (u === 'LINEAR_METER') checkPositive(ctx, ['fixed_width_m'], v.fixed_width_m, { max: 100, label: 'a largura' });

  // Variantes
  if (v.variants.length < 1) issue(ctx, ['variants'], 'Cadastre ao menos uma variante.');
  if (v.variants.length > 50) issue(ctx, ['variants'], 'No máximo 50 variantes.');
  const skus = new Map<string, number>();
  v.variants.forEach((va, i) => {
    const p = (f: string) => ['variants', i, f];
    const sku = va.sku.toUpperCase();
    if (!SKU_RE.test(sku)) issue(ctx, p('sku'), 'SKU: 3 a 40 caracteres (A–Z, 0–9 e hífen).');
    else if (skus.has(sku)) issue(ctx, p('sku'), `SKU repetido (linha ${skus.get(sku)! + 1}).`);
    skus.set(sku, i);
    if (va.gtin && !/^\d{8,14}$/.test(va.gtin)) issue(ctx, p('gtin'), 'GTIN: 8 a 14 dígitos.');
    if (va.name.length < 1 || va.name.length > 200) issue(ctx, p('name'), 'Informe o nome da variante.');
    if (va.price_cents === null || va.price_cents <= 0) issue(ctx, p('price_cents'), 'Informe o preço.');
    if (va.promo_price_cents !== null) {
      if (va.promo_price_cents <= 0) issue(ctx, p('promo_price_cents'), 'Deve ser maior que zero.');
      else if (va.price_cents !== null && va.promo_price_cents >= va.price_cents) issue(ctx, p('promo_price_cents'), 'O preço promocional deve ser menor que o preço.');
    }
    if (va.promo_starts_at && va.promo_ends_at && va.promo_ends_at <= va.promo_starts_at) issue(ctx, p('promo_ends_at'), 'O fim deve ser depois do início.');
    if (va.cost_cents !== null && va.cost_cents < 0) issue(ctx, p('cost_cents'), 'Não pode ser negativo.');
    if (va.weight_grams === null || va.weight_grams < 0) issue(ctx, p('weight_grams'), 'Informe o peso (0 = não informado).');
    checkCm(ctx, p('package_length_cm'), va.package_length_cm);
    checkCm(ctx, p('package_width_cm'), va.package_width_cm);
    checkCm(ctx, p('package_height_cm'), va.package_height_cm);
    if (u === 'ROLL') checkPositive(ctx, p('roll_length_m'), va.roll_length_m, { label: 'o comprimento do rolo' });
    if (u === 'BOX' && (va.units_per_box === null || va.units_per_box <= 0)) issue(ctx, p('units_per_box'), 'Informe as unidades por caixa.');
    if (va.units_per_package !== null && va.units_per_package <= 0) issue(ctx, p('units_per_package'), 'Deve ser maior que zero.');
    if (u === 'SQUARE_METER' || u === 'LINEAR_METER') checkPositive(ctx, p('fixed_width_m'), va.fixed_width_m, { max: 100, label: 'a largura' });
    if (va.id === null && va.initial_stock !== '') {
      const m = toMilli(va.initial_stock);
      if (m === null || m < 0) issue(ctx, p('initial_stock'), 'Valor inválido.');
      else if (['UNIT', 'ROLL', 'BOX'].includes(u) && m % 1000 !== 0) issue(ctx, p('initial_stock'), 'Use um número inteiro.');
    }
    const attrs = parseAttributes(va.attributes);
    if (attrs === null) issue(ctx, p('attributes'), 'Use o formato "cor: Branco; acabamento: Brilho" (até 10 atributos).');
  });
});

/** "cor: Branco; acabamento: Brilho" → {cor: "Branco", acabamento: "Brilho"}; null se inválido. */
export function parseAttributes(s: string): Record<string, string> | null {
  const out: Record<string, string> = {};
  const parts = s.split(';').map((x) => x.trim()).filter(Boolean);
  if (parts.length > 10) return null;
  for (const part of parts) {
    const idx = part.indexOf(':');
    if (idx <= 0) return null;
    const k = part.slice(0, idx).trim();
    const val = part.slice(idx + 1).trim();
    if (!k || k.length > 40 || !val || val.length > 100) return null;
    out[k] = val;
  }
  return out;
}
export const formatAttributes = (a: Record<string, string>) => Object.entries(a).map(([k, v]) => `${k}: ${v}`).join('; ');

export function emptyVariant(): VariantForm {
  return {
    id: null, sku: '', gtin: '', name: 'Padrão', attributes: '', price_cents: null, promo_price_cents: null, promo_starts_at: '', promo_ends_at: '',
    cost_cents: null, weight_grams: null, package_length_cm: '', package_width_cm: '', package_height_cm: '', roll_length_m: '', units_per_box: null,
    units_per_package: null, fixed_width_m: '', is_active: true, initial_stock: '',
  };
}

export function emptyProduct(): ProductForm {
  return {
    name: '', slug: '', short_description: '', description_html: '', brand_id: null, primary_category_id: null, category_ids: [], specifications: [],
    sale_unit: 'UNIT', min_quantity: '1', max_quantity: '', quantity_step: '1', width_mode: 'fixed', fixed_width_m: '', min_width_m: '', max_width_m: '',
    min_height_m: '', max_height_m: '', min_billable_area_m2: '', meta_title: '', meta_description: '', is_active: false, is_featured: false, pickup_only: false,
    variants: [emptyVariant()],
  };
}

const s = (n: number | null | undefined) => (n === null || n === undefined ? '' : decimalToInput(n).replace(',', '.'));

export function productToForm(p: AdminProduct): ProductForm {
  return {
    name: p.name, slug: p.slug, short_description: p.short_description ?? '', description_html: p.description_html ?? '', brand_id: p.brand_id,
    primary_category_id: p.primary_category_id, category_ids: p.category_ids.filter((c) => c !== p.primary_category_id), specifications: p.specifications,
    sale_unit: p.sale_unit, min_quantity: s(p.min_quantity), max_quantity: s(p.max_quantity), quantity_step: s(p.quantity_step),
    width_mode: p.min_width_m !== null || p.max_width_m !== null ? 'variable' : 'fixed', fixed_width_m: s(p.fixed_width_m), min_width_m: s(p.min_width_m),
    max_width_m: s(p.max_width_m), min_height_m: s(p.min_height_m), max_height_m: s(p.max_height_m), min_billable_area_m2: s(p.min_billable_area_m2),
    meta_title: p.meta_title ?? '', meta_description: p.meta_description ?? '', is_active: p.is_active, is_featured: p.is_featured, pickup_only: p.pickup_only,
    variants: p.variants.map(variantToForm),
  };
}

function variantToForm(v: AdminVariant): VariantForm {
  return {
    id: v.id, sku: v.sku, gtin: v.gtin ?? '', name: v.name, attributes: formatAttributes(v.attributes), price_cents: v.price_cents, promo_price_cents: v.promo_price_cents,
    promo_starts_at: isoToLocalInput(v.promo_starts_at), promo_ends_at: isoToLocalInput(v.promo_ends_at), cost_cents: v.cost_cents, weight_grams: v.weight_grams,
    package_length_cm: s(v.package_length_cm), package_width_cm: s(v.package_width_cm), package_height_cm: s(v.package_height_cm), roll_length_m: s(v.roll_length_m),
    units_per_box: v.units_per_box, units_per_package: v.units_per_package, fixed_width_m: s(v.fixed_width_m), is_active: v.is_active, initial_stock: '',
  };
}

const num = (v: string): number | null => (v === '' ? null : Number(v));

/** Monta o corpo da API: campos fora da unidade vão `null`; preço só com `prices.manage`; estoque inicial só em variante nova. */
export function formToPayload(f: ProductForm, opts: { canPrices: boolean; canMoveStock: boolean }): Record<string, unknown> {
  const u = f.sale_unit;
  const sq = u === 'SQUARE_METER';
  const fixedAllowed = sq || u === 'LINEAR_METER';
  return {
    name: f.name, slug: f.slug || undefined, short_description: f.short_description || null, description_html: f.description_html || null,
    specifications: f.specifications, sale_unit: u, brand_id: f.brand_id, primary_category_id: f.primary_category_id,
    category_ids: Array.from(new Set([f.primary_category_id!, ...f.category_ids])),
    min_quantity: num(f.min_quantity), max_quantity: num(f.max_quantity), quantity_step: num(f.quantity_step),
    min_billable_area_m2: sq ? num(f.min_billable_area_m2) : null,
    fixed_width_m: fixedAllowed && (u === 'LINEAR_METER' || f.width_mode === 'fixed') ? num(f.fixed_width_m) : null,
    min_width_m: sq && f.width_mode === 'variable' ? num(f.min_width_m) : null,
    max_width_m: sq && f.width_mode === 'variable' ? num(f.max_width_m) : null,
    min_height_m: sq ? num(f.min_height_m) : null,
    max_height_m: sq ? num(f.max_height_m) : null,
    meta_title: f.meta_title || null, meta_description: f.meta_description || null,
    is_active: f.is_active, is_featured: f.is_featured, pickup_only: f.pickup_only,
    variants: f.variants.map((v, i) => {
      const out: Record<string, unknown> = {
        sku: v.sku.toUpperCase(), gtin: v.gtin || null, name: v.name, attributes: parseAttributes(v.attributes) ?? {},
        weight_grams: v.weight_grams ?? 0, package_length_cm: num(v.package_length_cm), package_width_cm: num(v.package_width_cm),
        package_height_cm: num(v.package_height_cm), roll_length_m: u === 'ROLL' ? num(v.roll_length_m) : null,
        units_per_box: u === 'BOX' ? v.units_per_box : null, units_per_package: v.units_per_package,
        fixed_width_m: fixedAllowed ? num(v.fixed_width_m) : null, is_active: v.is_active, position: i,
      };
      if (v.id !== null) out.id = v.id;
      if (opts.canPrices) {
        out.price_cents = v.price_cents;
        out.promo_price_cents = v.promo_price_cents;
        out.promo_starts_at = localInputToIso(v.promo_starts_at);
        out.promo_ends_at = localInputToIso(v.promo_ends_at);
        out.cost_cents = v.cost_cents;
      } else if (v.id === null) {
        out.price_cents = v.price_cents;
      }
      if (v.id === null && opts.canMoveStock && v.initial_stock !== '') out.initial_stock = Number(v.initial_stock);
      return out;
    }),
  };
}

/** Rótulo dinâmico do peso por unidade de venda (UX §5.6). */
export const WEIGHT_UNIT_LABEL: Record<SaleUnit, string> = { UNIT: 'g/un', LINEAR_METER: 'g/m', SQUARE_METER: 'g/m²', ROLL: 'g/rolo', KG: 'g/kg', BOX: 'g/cx' };

export function slugify(s: string): string {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 220);
}
