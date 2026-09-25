import type { Decimal3, Grams, LineConfiguration, SaleUnit } from '@/shared/api/types';

const NBSP = ' ';

/**
 * Normaliza texto numérico pt-BR para string decimal da API ("1.234,5" → "1234.5").
 * Com vírgula: vírgula é decimal e pontos são milhar. Sem vírgula: um único ponto é decimal.
 * Retorna null se inválido ou com mais casas que `maxDecimals`.
 */
export function parseDecimal(input: string, maxDecimals = 3): string | null {
  const s = input.replace(/[\s ]/g, '');
  if (s === '') return null;
  let intPart: string;
  let frac = '';
  if (s.includes(',')) {
    const [i, f, ...rest] = s.split(',');
    if (rest.length) return null;
    if (i !== '' && !/^\d{1,3}(\.\d{3})*$|^\d+$/.test(i)) return null;
    intPart = i.replace(/\./g, '');
    frac = f;
  } else {
    const parts = s.split('.');
    if (parts.length > 2) return null;
    intPart = parts[0];
    frac = parts[1] ?? '';
  }
  if (intPart === '') intPart = '0';
  if (!/^\d+$/.test(intPart) || !/^\d*$/.test(frac)) return null;
  if (frac.length > maxDecimals) return null;
  const normInt = intPart.replace(/^0+(?=\d)/, '');
  const normFrac = frac.replace(/0+$/, '');
  return normFrac ? `${normInt}.${normFrac}` : normInt;
}

/** "1.25" → 1250 (milésimos, inteiro). */
export function toMilli(value: string | number): number | null {
  const norm = typeof value === 'number' ? parseDecimal(String(value)) : parseDecimal(value);
  if (norm === null) return null;
  const [i, f = ''] = norm.split('.');
  return Number(i) * 1000 + Number(f.padEnd(3, '0'));
}

/** Milésimos → número JSON com até 3 casas (API §1.5). */
export function milliToApi(milli: number): Decimal3 {
  return Number(`${Math.trunc(milli / 1000)}.${String(Math.abs(milli % 1000)).padStart(3, '0')}`);
}

/** String decimal da API → texto de edição com vírgula ("1.5" → "1,5"). */
export function decimalToInput(value: Decimal3 | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '';
  return String(value).replace('.', ',');
}

export function formatDecimal(value: number, minDigits = 0, maxDigits = 3): string {
  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: minDigits,
    maximumFractionDigits: maxDigits,
  }).format(value);
}

export const SALE_UNIT_ABBR: Record<SaleUnit, string> = {
  UNIT: 'un',
  LINEAR_METER: 'm',
  SQUARE_METER: 'm²',
  ROLL: 'rolo',
  KG: 'kg',
  BOX: 'cx',
};

export const SALE_UNIT_LABEL: Record<SaleUnit, string> = {
  UNIT: 'Unidade',
  LINEAR_METER: 'Metro linear',
  SQUARE_METER: 'Metro quadrado',
  ROLL: 'Rolo',
  KG: 'Quilograma',
  BOX: 'Caixa',
};

/** Quantidade com unidade (UX §6.1): "5 un", "5,5 m", "3,00 m²", "2 rolos", "1 caixa", "2,5 kg". */
export function formatQuantity(value: Decimal3 | null | undefined, unit: SaleUnit): string {
  if (value === null || value === undefined) return '—';
  const v = Number(value);
  switch (unit) {
    case 'UNIT':
      return `${formatDecimal(v)}${NBSP}un`;
    case 'LINEAR_METER':
      return `${formatDecimal(v)}${NBSP}m`;
    case 'SQUARE_METER':
      return `${formatDecimal(v, 2, 3)}${NBSP}m²`;
    case 'ROLL':
      return `${formatDecimal(v)}${NBSP}${v === 1 ? 'rolo' : 'rolos'}`;
    case 'BOX':
      return `${formatDecimal(v)}${NBSP}${v === 1 ? 'caixa' : 'caixas'}`;
    case 'KG':
      return `${formatDecimal(v)}${NBSP}kg`;
  }
}

/** Quantidade com abreviação livre (estoque: un | m | m² | rolo | kg | cx). */
export function formatStock(value: Decimal3 | null | undefined, abbr: string): string {
  if (value === null || value === undefined) return '—';
  const digits = abbr === 'm²' ? 2 : 0;
  return `${formatDecimal(Number(value), digits, 3)}${NBSP}${abbr}`;
}

/** Peso: < 1000 g → "850 g"; < 10 kg → 1 casa; ≥ 10 kg → inteiro. */
export function formatWeight(grams: Grams | null | undefined): string {
  if (grams === null || grams === undefined) return '—';
  if (grams < 1000) return `${grams}${NBSP}g`;
  const kg = grams / 1000;
  if (kg < 10) return `${formatDecimal(kg, 0, 1)}${NBSP}kg`;
  return `${formatDecimal(Math.round(kg), 0, 0)}${NBSP}kg`;
}

export function formatMeters(m: Decimal3 | null | undefined): string {
  if (m === null || m === undefined) return '—';
  return `${formatDecimal(Number(m), 2, 3)}${NBSP}m`;
}

export function formatArea(m2: Decimal3 | null | undefined): string {
  if (m2 === null || m2 === undefined) return '—';
  return `${formatDecimal(Number(m2), 2, 3)}${NBSP}m²`;
}

/** Configuração de linha: "1,20 m × 2,50 m × 1 peça = 3,00 m²" ou "5 m". */
export function formatConfiguration(
  config: LineConfiguration,
  unit: SaleUnit,
  areaM2: Decimal3 | null = null,
): string {
  if (unit === 'SQUARE_METER' && config.width_m !== null && config.height_m !== null) {
    const pieces = config.pieces ?? 1;
    const base = `${formatMeters(config.width_m)} × ${formatMeters(config.height_m)} × ${pieces}${NBSP}${
      pieces === 1 ? 'peça' : 'peças'
    }`;
    return areaM2 !== null ? `${base} = ${formatArea(areaM2)}` : base;
  }
  return formatQuantity(config.quantity, unit);
}

/** Dimensões de embalagem: "125 × 10 × 10 cm". */
export function formatPackage(l: number | null, w: number | null, h: number | null): string {
  if (l === null || w === null || h === null) return '—';
  return `${formatDecimal(l, 0, 1)} × ${formatDecimal(w, 0, 1)} × ${formatDecimal(h, 0, 1)}${NBSP}cm`;
}
