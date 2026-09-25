import type { PriceTierDisplay, SaleUnit } from '../api/types';
import { toMilli } from './decimal';

// Espelho exato do backend (ADR-003, ADR-019, RN-QTD-020/030/031). Somente inteiros.

/** round_half_up(numerator / divisor) para inteiros não negativos. */
export function roundHalfUpDiv(numerator: bigint, divisor: bigint): bigint {
  const q = numerator / divisor;
  const r = numerator % divisor;
  return r * 2n >= divisor ? q + 1n : q;
}

/** ceil(numerator / divisor) para inteiros não negativos. */
export function ceilDiv(numerator: bigint, divisor: bigint): bigint {
  return (numerator + divisor - 1n) / divisor;
}

/** Total da linha = round_half_up(unit_price_cents × quantity_milli / 1000). */
export function lineTotalCents(unitPriceCents: number, quantityMilli: number): number {
  return Number(roundHalfUpDiv(BigInt(unitPriceCents) * BigInt(quantityMilli), 1000n));
}

/** Área de 1 peça em milésimos de m² = round_half_up(width_mm × height_mm / 1000). */
export function pieceAreaMilli(widthMm: number, heightMm: number): number {
  return Number(roundHalfUpDiv(BigInt(widthMm) * BigInt(heightMm), 1000n));
}

export interface SquareMeterResult {
  pieceAreaMilli: number;
  /** Área real total (baixa de estoque). */
  areaMilli: number;
  /** Área faturada: max(área da peça, mínimo) × peças (ADR-019, mínimo por peça). */
  billableMilli: number;
  minAreaApplied: boolean;
}

export function squareMeterArea(
  widthMm: number,
  heightMm: number,
  pieces: number,
  minBillableAreaMilli: number | null,
): SquareMeterResult {
  const piece = pieceAreaMilli(widthMm, heightMm);
  const minArea = minBillableAreaMilli ?? 0;
  const billablePiece = Math.max(piece, minArea);
  return {
    pieceAreaMilli: piece,
    areaMilli: piece * pieces,
    billableMilli: billablePiece * pieces,
    minAreaApplied: billablePiece > piece,
  };
}

/** Peso estimado: ceil(weight_grams × billable_milli / 1000); KG → faturado em gramas. */
export function estimateWeightGrams(saleUnit: SaleUnit, weightPerUnitGrams: number, billableMilli: number): number {
  if (saleUnit === 'KG') return billableMilli;
  return Number(ceilDiv(BigInt(weightPerUnitGrams) * BigInt(billableMilli), 1000n));
}

/** Faixa aplicável: maior `min_quantity` ≤ quantidade faturada. */
export function findTier(tiers: PriceTierDisplay[], billableMilli: number): PriceTierDisplay | null {
  let best: PriceTierDisplay | null = null;
  for (const t of tiers) {
    const min = toMilli(t.min_quantity);
    if (min <= billableMilli && (best === null || min > toMilli(best.min_quantity))) best = t;
  }
  return best;
}

export function nextTier(tiers: PriceTierDisplay[], billableMilli: number): PriceTierDisplay | null {
  let best: PriceTierDisplay | null = null;
  for (const t of tiers) {
    const min = toMilli(t.min_quantity);
    if (min > billableMilli && (best === null || min < toMilli(best.min_quantity))) best = t;
  }
  return best;
}

export type StepCheck =
  | { ok: true }
  | { ok: false; reason: 'below_min' | 'above_max' | 'step'; suggestions: number[] };

/**
 * RN-QTD-010: válido se q % step == 0 (a partir de zero), q ≥ min, q ≤ max.
 * Sugestões = dois múltiplos válidos mais próximos (abaixo/acima), respeitando min/max.
 * Todos os valores em milésimos.
 */
export function checkStep(qMilli: number, minMilli: number, maxMilli: number | null, stepMilli: number): StepCheck {
  const step = Math.max(1, stepMilli);
  const clampValid = (v: number): number | null => {
    if (v < minMilli) {
      const up = Math.ceil(minMilli / step) * step;
      return maxMilli !== null && up > maxMilli ? null : up;
    }
    if (maxMilli !== null && v > maxMilli) {
      const down = Math.floor(maxMilli / step) * step;
      return down < minMilli ? null : down;
    }
    return v;
  };
  if (qMilli < minMilli) {
    const v = clampValid(minMilli);
    return { ok: false, reason: 'below_min', suggestions: v === null ? [] : [v] };
  }
  if (maxMilli !== null && qMilli > maxMilli) {
    const v = clampValid(maxMilli);
    return { ok: false, reason: 'above_max', suggestions: v === null ? [] : [v] };
  }
  if (qMilli % step !== 0) {
    const lower = Math.floor(qMilli / step) * step;
    const upper = lower + step;
    const set = new Set<number>();
    for (const v of [lower, upper]) {
      const c = clampValid(v);
      if (c !== null) set.add(c);
    }
    return { ok: false, reason: 'step', suggestions: [...set].sort((a, b) => a - b) };
  }
  return { ok: true };
}
