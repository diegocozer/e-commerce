// Aritmética decimal por string → inteiros (milésimos). Nunca parseFloat para calcular
// (ADR-003, RN-QTD-001). Quantidades: milésimos da unidade (5,5 m → 5500).
// Dimensões: metros → milímetros (1,22 m → 1220), que numericamente é o mesmo "milli".

export type DecimalParseError = 'empty' | 'invalid' | 'too_many_decimals' | 'too_large';

export type DecimalParseResult =
  | { ok: true; value: string; milli: number }
  | { ok: false; error: DecimalParseError };

const MAX_INT_DIGITS = 6; // API: ^\d{1,6}(\.\d{1,3})?$

/**
 * Aceita "5", "5,5", "5.5", "1.234,5", " 2,50 ". Rejeita negativos, notação
 * científica, texto e mais que `maxDecimals` casas.
 */
export function parseDecimal(input: string, maxDecimals = 3): DecimalParseResult {
  const s = input.trim().replace(/[\s ]/g, '');
  if (s === '') return { ok: false, error: 'empty' };
  if (!/^[\d.,]+$/.test(s)) return { ok: false, error: 'invalid' };
  let intPart: string;
  let frac = '';
  const lastComma = s.lastIndexOf(',');
  if (lastComma >= 0) {
    // vírgula é o separador decimal; pontos antes dela são milhar
    intPart = s.slice(0, lastComma).replace(/\./g, '');
    frac = s.slice(lastComma + 1);
    if (frac.includes('.')) return { ok: false, error: 'invalid' };
  } else {
    const dots = s.split('.').length - 1;
    if (dots === 0) intPart = s;
    else if (dots === 1) [intPart, frac] = s.split('.');
    else {
      // "1.234.567" → milhar
      if (!/^\d{1,3}(\.\d{3})+$/.test(s)) return { ok: false, error: 'invalid' };
      intPart = s.replace(/\./g, '');
    }
  }
  if (intPart === '') intPart = '0';
  if (!/^\d+$/.test(intPart) || !/^\d*$/.test(frac)) return { ok: false, error: 'invalid' };
  if (frac.length > maxDecimals) return { ok: false, error: 'too_many_decimals' };
  intPart = intPart.replace(/^0+(?=\d)/, '');
  if (intPart.length > MAX_INT_DIGITS) return { ok: false, error: 'too_large' };
  const fracTrim = frac.replace(/0+$/, '');
  const value = fracTrim ? `${intPart}.${fracTrim}` : intPart;
  return { ok: true, value, milli: decimalStringToMilli(value) };
}

/** "5.5" → 5500; "0.001" → 1. Entrada já normalizada (ponto, ≤ 3 casas). */
export function decimalStringToMilli(value: string): number {
  const [i, f = ''] = value.split('.');
  return Number(i) * 1000 + Number(f.padEnd(3, '0').slice(0, 3));
}

/** Número vindo da API (≤ 3 casas) → milésimos exatos. */
export function toMilli(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.round(value * 1000);
}

/** 5500 → "5.5" (para a API). */
export function milliToDecimalString(milli: number): string {
  const sign = milli < 0 ? '-' : '';
  const abs = Math.abs(Math.trunc(milli));
  const i = Math.floor(abs / 1000);
  const f = String(abs % 1000).padStart(3, '0').replace(/0+$/, '');
  return f ? `${sign}${i}.${f}` : `${sign}${i}`;
}

/** 5500 → 5.5 (número JSON com ≤ 3 casas, exato na serialização). */
export function milliToNumber(milli: number): number {
  return Number(milliToDecimalString(milli));
}

const intFmt = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });

/**
 * Formata milésimos em pt-BR sem float: 5500 → "5,5"; com minDecimals=2 → "5,50".
 * maxDecimals trunca/arredonda half-up nas casas descartadas.
 */
export function formatMilli(milli: number, minDecimals = 0, maxDecimals = 3): string {
  const negative = milli < 0;
  let abs = Math.abs(Math.trunc(milli));
  if (maxDecimals < 3) {
    const factor = 10 ** (3 - maxDecimals);
    const q = Math.floor(abs / factor);
    const r = abs % factor;
    abs = (r * 2 >= factor ? q + 1 : q) * factor;
  }
  const i = Math.floor(abs / 1000);
  let f = String(abs % 1000).padStart(3, '0').slice(0, maxDecimals);
  while (f.length > minDecimals && f.endsWith('0')) f = f.slice(0, -1);
  const body = f ? `${intFmt.format(i)},${f}` : intFmt.format(i);
  return negative ? `-${body}` : body;
}

/** Casas decimais significativas de um passo (0.1 → 1; 0.25 → 2; 1 → 0). */
export function stepDecimals(stepMilli: number): number {
  if (stepMilli % 1000 === 0) return 0;
  if (stepMilli % 100 === 0) return 1;
  if (stepMilli % 10 === 0) return 2;
  return 3;
}
