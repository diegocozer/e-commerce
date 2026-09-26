// Cálculo dos mocks — usa a MESMA aritmética inteira da loja (espelho do backend).
import type { CartItem, LineConfiguration, PricePreview, ProductVariant } from '@/shared/api/types';
import { checkConfiguration, type ConfigField, type LineBody, type ValidConfiguration } from '@/shared/saleUnit/configuration';
import { formatMilli, milliToNumber, toMilli } from '@/shared/saleUnit/decimal';
import { estimateWeightGrams, findTier, lineTotalCents, nextTier } from '@/shared/saleUnit/math';
import { formatConfiguration } from '@/shared/formatters/quantity';
import { findVariant, productDetail, stock } from './data';

export interface LineInputBody {
  variant_id?: unknown;
  quantity?: unknown;
  width_m?: unknown;
  height_m?: unknown;
  pieces?: unknown;
}

export type ValidationResult =
  | { ok: true; variant: ProductVariant; productSlug: string; valid: ValidConfiguration }
  | { ok: false; status: 422; body: { message: string; errors: Record<string, string[]>; details?: Record<string, { suggestions: number[] }> } };

const str = (v: unknown) => (v === undefined || v === null ? '' : String(v).replace('.', ','));

export function validateLine(body: LineInputBody, prefix = ''): ValidationResult {
  const found = typeof body.variant_id === 'number' ? findVariant(body.variant_id) : undefined;
  if (!found) {
    return { ok: false, status: 422, body: { message: 'Produto indisponível.', errors: { [`${prefix}variant_id`]: ['Produto indisponível.'] } } };
  }
  const detail = productDetail(found.product);
  const variant = detail.variants.find((v) => v.id === found.variant.id)!;
  const r = variant.rules;
  const draft = {
    quantity: str(body.quantity),
    width: r.fixed_width_m !== null ? formatMilli(toMilli(r.fixed_width_m), 2, 2) : str(body.width_m),
    height: str(body.height_m),
    pieces: str(body.pieces),
  };
  const check = checkConfiguration(r, draft);
  if (!check.ok) {
    const errors: Record<string, string[]> = {};
    const details: Record<string, { suggestions: number[] }> = {};
    for (const [field, issue] of Object.entries(check.errors) as [ConfigField, { message: string; suggestionsMilli?: number[] }][]) {
      errors[`${prefix}${field}`] = [issue.message];
      if (issue.suggestionsMilli?.length) details[`${prefix}${field}`] = { suggestions: issue.suggestionsMilli.map(milliToNumber) };
    }
    const first = Object.values(errors)[0][0];
    return { ok: false, status: 422, body: { message: first, errors, ...(Object.keys(details).length ? { details } : {}) } };
  }
  return { ok: true, variant, productSlug: detail.slug, valid: check };
}

export function priceFor(variant: ProductVariant, billableMilli: number) {
  const tier = findTier(variant.price.tiers, billableMilli);
  return { unit: tier?.unit_price_cents ?? variant.price.unit_price_cents, tier };
}

export function buildPreview(variant: ProductVariant, valid: ValidConfiguration): PricePreview {
  const { unit, tier } = priceFor(variant, valid.billableMilli);
  const nt = nextTier(variant.price.tiers, valid.billableMilli);
  const available = stock.get(variant.id) ?? 0;
  const sufficient = valid.stockMilli <= toMilli(available);
  return {
    variant_id: variant.id,
    sale_unit: variant.rules.sale_unit,
    configuration: valid.configuration,
    configuration_label: formatConfiguration(valid.configuration, variant.rules.sale_unit),
    billable_quantity: milliToNumber(valid.billableMilli),
    stock_quantity: milliToNumber(valid.stockMilli),
    piece_area_m2: valid.pieceAreaMilli === null ? null : milliToNumber(valid.pieceAreaMilli),
    area_m2: valid.areaMilli === null ? null : milliToNumber(valid.areaMilli),
    min_area_applied: valid.minAreaApplied,
    unit_price_cents: unit,
    base_unit_price_cents: variant.price.base_unit_price_cents,
    compare_at_cents: null,
    price_source: tier?.price_source ?? 'base',
    price_source_label: tier && tier.price_source === 'tier' ? 'Preço por quantidade' : null,
    line_total_cents: lineTotalCents(unit, valid.billableMilli),
    weight_grams: estimateWeightGrams(variant.rules.sale_unit, variant.weight_grams, valid.billableMilli),
    applied_tier: tier,
    next_tier: nt ? { ...nt, missing_quantity: milliToNumber(toMilli(nt.min_quantity) - valid.billableMilli) } : null,
    stock: { sufficient, available_quantity: sufficient ? null : available },
  };
}

export interface StoredLine {
  id: number;
  variant_id: number;
  body: LineBody;
  lastSeenUnitPrice: number | null;
}

/** Monta um CartItem recalculado. `variantSumMilli` = soma da variante no carrinho (faixas — ADR-019). */
export function buildCartItem(line: StoredLine, variantSumMilli: number): CartItem {
  const found = findVariant(line.variant_id)!;
  const detail = productDetail(found.product);
  const variant = detail.variants.find((v) => v.id === line.variant_id)!;
  const res = validateLine({ variant_id: line.variant_id, ...line.body });
  const base = {
    id: line.id,
    variant_id: line.variant_id,
    product: { id: detail.id, slug: detail.slug, name: detail.name, url_path: detail.url_path, image: detail.images[0] ?? null },
    variant: { id: variant.id, sku: variant.sku, name: variant.name, attributes: variant.attributes },
    sale_unit: detail.sale_unit,
    sale_unit_abbr: detail.sale_unit_abbr,
    rules: variant.rules,
    availability: variant.availability,
  };
  if (!res.ok) {
    const cfg: LineConfiguration = { quantity: null, width_m: null, height_m: null, pieces: null, ...(line.body as Partial<LineConfiguration>) };
    return {
      ...base, configuration: cfg, configuration_label: formatConfiguration(cfg, detail.sale_unit), billable_quantity: null, stock_quantity: null, piece_area_m2: null, area_m2: null,
      min_area_applied: false, unit_price_cents: null, base_unit_price_cents: null, compare_at_cents: null, price_source: null, price_source_label: null, line_total_cents: null,
      weight_grams: 0, status: 'invalid_quantity', warnings: [{ code: 'invalid_quantity', message: res.body.message, suggestions: [] }],
    };
  }
  const v = res.valid;
  const { unit, tier } = priceFor(variant, variantSumMilli);
  const available = stock.get(variant.id) ?? 0;
  const insufficient = v.stockMilli > toMilli(available);
  const warnings: CartItem['warnings'] = [];
  if (line.lastSeenUnitPrice !== null && line.lastSeenUnitPrice !== unit) warnings.push({ code: 'price_changed', previous_unit_price_cents: line.lastSeenUnitPrice, current_unit_price_cents: unit });
  if (insufficient) warnings.push({ code: 'insufficient_stock', requested_quantity: milliToNumber(v.stockMilli), available_quantity: available, message: `Disponível: ${available}` });
  if (v.minAreaApplied && v.areaMilli !== null) warnings.push({ code: 'min_area_applied', area_m2: milliToNumber(v.areaMilli), billable_area_m2: milliToNumber(v.billableMilli) });
  return {
    ...base,
    configuration: v.configuration,
    configuration_label: formatConfiguration(v.configuration, detail.sale_unit),
    billable_quantity: milliToNumber(v.billableMilli),
    stock_quantity: milliToNumber(v.stockMilli),
    piece_area_m2: v.pieceAreaMilli === null ? null : milliToNumber(v.pieceAreaMilli),
    area_m2: v.areaMilli === null ? null : milliToNumber(v.areaMilli),
    min_area_applied: v.minAreaApplied,
    unit_price_cents: unit,
    base_unit_price_cents: variant.price.base_unit_price_cents,
    compare_at_cents: null,
    price_source: tier?.price_source ?? 'base',
    price_source_label: tier?.price_source === 'tier' ? 'Preço por quantidade' : null,
    line_total_cents: lineTotalCents(unit, v.billableMilli),
    weight_grams: estimateWeightGrams(detail.sale_unit, variant.weight_grams, v.billableMilli),
    status: insufficient ? 'insufficient_stock' : 'ok',
    warnings,
  };
}
