import type { LineConfiguration, PriceTierDisplay, SaleUnit, SaleUnitRules } from '../api/types';
import { formatMilli, milliToNumber, parseDecimal, stepDecimals, toMilli } from './decimal';
import { NBSP, isIntegerUnit } from './labels';
import { checkStep, estimateWeightGrams, findTier, lineTotalCents, squareMeterArea } from './math';

// Validação local do configurador (espelho de RN-QTD / API §3.A price-preview). É
// conveniência: o backend é a autoridade e a resposta 422 dele sempre prevalece.

export type ConfigField = 'quantity' | 'width_m' | 'height_m' | 'pieces';

export interface QuantityDraft {
  quantity: string;
  width: string;
  height: string;
  pieces: string;
}

export interface FieldIssue {
  message: string;
  /** Sugestões em milésimos (quantidade) ou peças inteiras (×1000). */
  suggestionsMilli?: number[];
}

/** Corpo sem `variant_id` para price-preview / carrinho / frete. */
export type LineBody =
  | { quantity: number }
  | { width_m?: number; height_m: number; pieces: number };

export interface ValidConfiguration {
  ok: true;
  body: LineBody;
  configuration: LineConfiguration;
  /** Quantidade faturável/estoque local (milésimos), já com área mínima quando m². */
  billableMilli: number;
  stockMilli: number;
  pieceAreaMilli: number | null;
  areaMilli: number | null;
  minAreaApplied: boolean;
}

export interface InvalidConfiguration {
  ok: false;
  errors: Partial<Record<ConfigField, FieldIssue>>;
  /** true quando só falta preencher (não mostrar erro antes da interação). */
  incomplete: boolean;
}

export type ConfigurationCheck = ValidConfiguration | InvalidConfiguration;

const UNIT_SHORT: Record<SaleUnit, string> = {
  UNIT: 'un',
  LINEAR_METER: 'm',
  SQUARE_METER: 'm²',
  ROLL: 'rolos',
  KG: 'kg',
  BOX: 'cx',
};

function fmtQty(milli: number, unit: SaleUnit, decimals: number): string {
  return `${formatMilli(milli, decimals, Math.max(decimals, 3))}${NBSP}${UNIT_SHORT[unit]}`;
}

/** "Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m." (formato do backend, API §4.2). */
export function stepMessage(stepMilli: number, suggestions: number[], unit: SaleUnit): string {
  const dec = isIntegerUnit(unit) ? 0 : Math.max(2, stepDecimals(stepMilli));
  const s = suggestions.map((v) => fmtQty(v, unit, dec));
  const tail = s.length ? ` Sugestões: ${s.join(' ou ')}.` : '';
  return `Use múltiplos de ${fmtQty(stepMilli, unit, dec)}.${tail}`;
}

function quantityIssue(
  qMilli: number,
  rules: SaleUnitRules,
  unit: SaleUnit,
  label: 'quantity' | 'pieces',
): FieldIssue | null {
  const min = toMilli(rules.min_quantity);
  const max = rules.max_quantity === null ? null : toMilli(rules.max_quantity);
  const step = toMilli(rules.quantity_step);
  const check = checkStep(qMilli, min, max, step);
  if (check.ok) return null;
  const u: SaleUnit = label === 'pieces' ? 'UNIT' : unit;
  const noun = label === 'pieces' ? 'peças' : UNIT_SHORT[unit];
  const dec = isIntegerUnit(u) ? 0 : 0;
  if (check.reason === 'below_min') {
    return {
      message: `Quantidade mínima: ${formatMilli(min, dec)}${NBSP}${noun}.`,
      suggestionsMilli: check.suggestions,
    };
  }
  if (check.reason === 'above_max') {
    return {
      message: `Quantidade máxima: ${formatMilli(max ?? 0, dec)}${NBSP}${noun}.`,
      suggestionsMilli: check.suggestions,
    };
  }
  return {
    message: label === 'pieces' ? `Peças em múltiplos de ${formatMilli(step)}.` : stepMessage(step, check.suggestions, unit),
    suggestionsMilli: check.suggestions,
  };
}

function parseDimension(
  raw: string,
  label: 'Largura' | 'Altura',
  minM: number | null,
  maxM: number | null,
): { mm: number } | { issue: FieldIssue; empty: boolean } {
  const parsed = parseDecimal(raw, 2);
  if (!parsed.ok) {
    if (parsed.error === 'empty') return { issue: { message: `Informe a ${label.toLowerCase()}.` }, empty: true };
    if (parsed.error === 'too_many_decimals') {
      return { issue: { message: `${label}: use até 2 casas decimais (centímetros).` }, empty: false };
    }
    return { issue: { message: `${label} inválida.` }, empty: false };
  }
  const mm = parsed.milli; // metros × 1000 = milímetros
  const lo = Math.max(10, minM === null ? 10 : toMilli(minM));
  const hi = Math.min(100_000, maxM === null ? 100_000 : toMilli(maxM));
  if (mm < lo || mm > hi) {
    return {
      issue: { message: `${label} entre ${formatMilli(lo, 2, 2)}${NBSP}m e ${formatMilli(hi, 2, 2)}${NBSP}m.` },
      empty: false,
    };
  }
  return { mm };
}

/** Converte o rascunho do configurador em corpo de API + números locais. */
export function checkConfiguration(rules: SaleUnitRules, draft: QuantityDraft): ConfigurationCheck {
  const unit = rules.sale_unit;
  const errors: Partial<Record<ConfigField, FieldIssue>> = {};
  let incomplete = false;

  if (unit !== 'SQUARE_METER') {
    const parsed = parseDecimal(draft.quantity, isIntegerUnit(unit) ? 3 : 3);
    if (!parsed.ok) {
      if (parsed.error === 'empty') {
        incomplete = true;
        errors.quantity = { message: 'Informe a quantidade.' };
      } else if (parsed.error === 'too_many_decimals') {
        errors.quantity = { message: 'Use no máximo 3 casas decimais.' };
      } else if (parsed.error === 'too_large') {
        errors.quantity = { message: 'Quantidade acima do limite.' };
      } else {
        errors.quantity = { message: 'Quantidade inválida.' };
      }
      return { ok: false, errors, incomplete };
    }
    const q = parsed.milli;
    if (q <= 0) return { ok: false, errors: { quantity: { message: 'Informe uma quantidade maior que zero.' } }, incomplete: false };
    if (isIntegerUnit(unit) && q % 1000 !== 0) {
      return { ok: false, errors: { quantity: { message: 'Quantidade deve ser inteira.' } }, incomplete: false };
    }
    if (q > 100_000_000) {
      return { ok: false, errors: { quantity: { message: 'Quantidade acima do limite.' } }, incomplete: false };
    }
    const issue = quantityIssue(q, rules, unit, 'quantity');
    if (issue) return { ok: false, errors: { quantity: issue }, incomplete: false };
    const quantity = milliToNumber(q);
    return {
      ok: true,
      body: { quantity },
      configuration: { quantity, width_m: null, height_m: null, pieces: null },
      billableMilli: q,
      stockMilli: q,
      pieceAreaMilli: null,
      areaMilli: null,
      minAreaApplied: false,
    };
  }

  // SQUARE_METER — largura (fixa ou informada) × altura × peças
  let widthMm: number | null = null;
  const fixed = rules.fixed_width_m;
  if (fixed !== null) {
    widthMm = toMilli(fixed);
  } else {
    const w = parseDimension(draft.width, 'Largura', rules.min_width_m, rules.max_width_m);
    if ('mm' in w) widthMm = w.mm;
    else {
      errors.width_m = w.issue;
      if (w.empty) incomplete = true;
    }
  }
  const h = parseDimension(draft.height, 'Altura', rules.min_height_m, rules.max_height_m);
  let heightMm: number | null = null;
  if ('mm' in h) heightMm = h.mm;
  else {
    errors.height_m = h.issue;
    if (h.empty) incomplete = true;
  }

  // RN-QTD-038: largura acima do máximo mas medidas invertidas caberiam
  if (errors.width_m && fixed === null && rules.max_width_m !== null) {
    const wp = parseDecimal(draft.width, 2);
    const hp = parseDecimal(draft.height, 2);
    if (wp.ok && hp.ok && wp.milli > toMilli(rules.max_width_m)) {
      const inBounds = (v: number, lo: number | null, hi: number | null) =>
        (lo === null || v >= toMilli(lo)) && (hi === null || v <= toMilli(hi));
      if (inBounds(hp.milli, rules.min_width_m, rules.max_width_m) && inBounds(wp.milli, rules.min_height_m, rules.max_height_m)) {
        errors.width_m = { message: `${errors.width_m.message} Tente inverter largura e altura.` };
      }
    }
  }

  const piecesParsed = parseDecimal(draft.pieces, 0);
  let pieces: number | null = null;
  if (!piecesParsed.ok) {
    if (piecesParsed.error === 'empty') {
      incomplete = true;
      errors.pieces = { message: 'Informe o número de peças.' };
    } else errors.pieces = { message: 'Peças: use um número inteiro.' };
  } else {
    const p = piecesParsed.milli / 1000;
    if (p < 1 || p > rules.max_pieces) {
      errors.pieces = { message: `Peças entre 1 e ${rules.max_pieces}.` };
    } else {
      const issue = quantityIssue(piecesParsed.milli, rules, unit, 'pieces');
      if (issue) errors.pieces = issue;
      else pieces = p;
    }
  }

  if (widthMm === null || heightMm === null || pieces === null || Object.keys(errors).length > 0) {
    return { ok: false, errors, incomplete: incomplete && Object.values(errors).every((e) => e?.message.startsWith('Informe')) };
  }

  const minArea = rules.min_billable_area_m2 === null ? null : toMilli(rules.min_billable_area_m2);
  const area = squareMeterArea(widthMm, heightMm, pieces, minArea);
  const width_m = milliToNumber(widthMm);
  const height_m = milliToNumber(heightMm);
  return {
    ok: true,
    body: fixed !== null ? { height_m, pieces } : { width_m, height_m, pieces },
    configuration: { quantity: null, width_m, height_m, pieces },
    billableMilli: area.billableMilli,
    stockMilli: area.areaMilli,
    pieceAreaMilli: area.pieceAreaMilli,
    areaMilli: area.areaMilli,
    minAreaApplied: area.minAreaApplied,
  };
}

export interface LocalPreview {
  unitPriceCents: number;
  lineTotalCents: number;
  weightGrams: number;
  tier: PriceTierDisplay | null;
}

/** Prévia local: preço da faixa aplicável (ou unitário) × faturado, round half up. */
export function computeLocalPreview(
  unit: SaleUnit,
  valid: ValidConfiguration,
  price: { unit_price_cents: number; tiers: PriceTierDisplay[] },
  weightPerUnitGrams: number,
): LocalPreview {
  const tier = findTier(price.tiers, valid.billableMilli);
  const unitPriceCents = tier ? tier.unit_price_cents : price.unit_price_cents;
  return {
    unitPriceCents,
    lineTotalCents: lineTotalCents(unitPriceCents, valid.billableMilli),
    weightGrams: estimateWeightGrams(unit, weightPerUnitGrams, valid.billableMilli),
    tier,
  };
}

/** Rascunho inicial a partir das regras (quantidade mínima; 1 peça). */
export function initialDraft(rules: SaleUnitRules, fromConfig?: LineConfiguration | null): QuantityDraft {
  const fmt = (n: number | null | undefined, min = 0, max = 3) => (n == null ? '' : formatMilli(toMilli(n), min, max).replace(/\./g, ''));
  if (fromConfig) {
    return {
      quantity: fmt(fromConfig.quantity),
      width: rules.fixed_width_m !== null ? fmt(rules.fixed_width_m, 2, 2) : fmt(fromConfig.width_m, 2, 2),
      height: fmt(fromConfig.height_m, 2, 2),
      pieces: String(fromConfig.pieces ?? 1),
    };
  }
  return {
    quantity: rules.sale_unit === 'SQUARE_METER' ? '' : fmt(rules.min_quantity),
    width: rules.fixed_width_m !== null ? fmt(rules.fixed_width_m, 2, 2) : '',
    height: '',
    pieces: rules.sale_unit === 'SQUARE_METER' ? fmt(Math.max(1, rules.min_quantity)) : '1',
  };
}

/** Aplica um passo (+/-) ao valor digitado, respeitando min/max. Retorna texto pt-BR. */
export function stepValue(raw: string, rules: SaleUnitRules, direction: 1 | -1, factor = 1, integer = false): string {
  const step = integer && rules.sale_unit === 'SQUARE_METER' ? Math.max(1000, toMilli(rules.quantity_step)) : toMilli(rules.quantity_step);
  const min = toMilli(rules.min_quantity);
  const max = rules.max_quantity === null ? null : toMilli(rules.max_quantity);
  const parsed = parseDecimal(raw, 3);
  const current = parsed.ok ? parsed.milli : min;
  let next: number;
  if (direction === 1) next = (Math.floor(current / step) + factor) * step;
  else next = (Math.ceil(current / step) - factor) * step;
  if (next < min) next = Math.ceil(min / step) * step;
  if (max !== null && next > max) next = Math.floor(max / step) * step;
  return formatMilli(next).replace(/\./g, '');
}
