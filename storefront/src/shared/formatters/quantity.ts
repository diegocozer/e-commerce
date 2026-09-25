import type { LineConfiguration, SaleUnit } from '../api/types';
import { formatMilli, toMilli } from '../saleUnit/decimal';
import { NBSP } from '../saleUnit/labels';

// UX §6.1: número e unidade separados por espaço não separável.

function plural(n: number, one: string, many: string): string {
  return n === 1 ? one : many;
}

/** Quantidade em milésimos → "5 un", "5,5 m", "3,00 m²", "2 rolos", "2,5 kg", "1 caixa". */
export function formatQuantityMilli(milli: number, unit: SaleUnit): string {
  switch (unit) {
    case 'UNIT':
      return `${formatMilli(milli)}${NBSP}un`;
    case 'LINEAR_METER':
      return `${formatMilli(milli)}${NBSP}m`;
    case 'SQUARE_METER':
      return `${formatMilli(milli, 2, 2)}${NBSP}m²`;
    case 'KG':
      return `${formatMilli(milli)}${NBSP}kg`;
    case 'ROLL':
      return `${formatMilli(milli)}${NBSP}${milli === 1000 ? 'rolo' : 'rolos'}`;
    case 'BOX':
      return `${formatMilli(milli)}${NBSP}${milli === 1000 ? 'caixa' : 'caixas'}`;
  }
}

export function formatQuantity(value: number, unit: SaleUnit): string {
  return formatQuantityMilli(toMilli(value), unit);
}

/** Área em m² sempre com 2 casas: 3 → "3,00 m²". */
export function formatArea(valueM2: number): string {
  return `${formatMilli(toMilli(valueM2), 2, 2)}${NBSP}m²`;
}

export function formatAreaMilli(milli: number): string {
  return `${formatMilli(milli, 2, 2)}${NBSP}m²`;
}

/** Dimensão em metros com 2 casas: 1.22 → "1,22 m". */
export function formatMeters(valueM: number): string {
  return `${formatMilli(toMilli(valueM), 2, 2)}${NBSP}m`;
}

export function formatMetersMilli(mm: number): string {
  return `${formatMilli(mm, 2, 2)}${NBSP}m`;
}

export function formatPieces(n: number): string {
  return `${n}${NBSP}${plural(n, 'peça', 'peças')}`;
}

/** Peso: < 1000 g → "850 g"; < 10 kg → 1 casa ("1,2 kg"); ≥ 10 kg → inteiro ("12 kg"). */
export function formatWeight(grams: number): string {
  const g = Math.max(0, Math.round(grams));
  if (g < 1000) return `${g}${NBSP}g`;
  if (g < 10000) return `${formatMilli(g, 1, 1)}${NBSP}kg`;
  return `${formatMilli(g, 0, 0)}${NBSP}kg`;
}

/** "5 m" | "1,20 m × 2,50 m × 1 peça = 3,00 m²" (UX ConfigurationSummary). */
export function formatConfiguration(config: LineConfiguration, unit: SaleUnit, areaM2?: number | null): string {
  if (unit === 'SQUARE_METER') {
    const w = config.width_m ?? 0;
    const h = config.height_m ?? 0;
    const p = config.pieces ?? 1;
    const base = `${formatMeters(w)} × ${formatMeters(h)} × ${formatPieces(p)}`;
    return areaM2 != null ? `${base} = ${formatArea(areaM2)}` : base;
  }
  return formatQuantity(config.quantity ?? 0, unit);
}
