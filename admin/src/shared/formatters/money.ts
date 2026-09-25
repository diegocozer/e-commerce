import type { Cents } from '@/shared/api/types';

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const intGroup = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 });

/** 123456 → "R$ 1.234,56". A divisão por 100 acontece só para exibição (UX §6.1). */
export function formatBRL(cents: Cents | null | undefined): string {
  if (cents === null || cents === undefined) return '—';
  return brl.format(cents / 100);
}

/**
 * Converte texto digitado em BRL para centavos inteiros, sem ponto flutuante.
 * Aceita "1.234,56", "1234,56", "1234.56", "R$ 15,9", "15". Retorna null se inválido
 * (mais de 2 casas, caracteres estranhos, vazio).
 */
export function parseBRLToCents(input: string): Cents | null {
  let s = input.replace(/R\$/gi, '').replace(/[\s ]/g, '');
  if (s === '') return null;
  let negative = false;
  if (s.startsWith('-')) {
    negative = true;
    s = s.slice(1);
  }
  let intPart: string;
  let fracPart = '';
  if (s.includes(',')) {
    const pieces = s.split(',');
    if (pieces.length !== 2) return null;
    intPart = pieces[0].replace(/\./g, '');
    fracPart = pieces[1];
    if (!/^\d{1,3}(\.\d{3})*$|^\d+$/.test(pieces[0]) && pieces[0] !== '') return null;
  } else {
    const dots = s.split('.');
    if (dots.length === 2 && dots[1].length <= 2) {
      intPart = dots[0];
      fracPart = dots[1];
    } else {
      if (dots.length > 1 && !/^\d{1,3}(\.\d{3})+$/.test(s)) return null;
      intPart = s.replace(/\./g, '');
    }
  }
  if (intPart === '') intPart = '0';
  if (!/^\d+$/.test(intPart) || !/^\d{0,2}$/.test(fracPart)) return null;
  if (intPart.length > 13) return null;
  const cents = Number(intPart) * 100 + Number(fracPart.padEnd(2, '0'));
  return negative ? -cents : cents;
}

/** Centavos → texto editável no campo ("1.234,56"). */
export function centsToInput(cents: Cents | null | undefined): string {
  if (cents === null || cents === undefined) return '';
  const negative = cents < 0;
  const abs = Math.abs(Math.trunc(cents));
  const reais = Math.floor(abs / 100);
  const rest = abs % 100;
  return `${negative ? '-' : ''}${intGroup.format(reais)},${String(rest).padStart(2, '0')}`;
}

/** Basis points → "12,5%" (1000 bp = 10%). */
export function formatBp(bp: number | null | undefined, opts: { signed?: boolean } = {}): string {
  if (bp === null || bp === undefined) return '—';
  const pct = bp / 100;
  const txt = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 2 }).format(Math.abs(pct));
  const sign = bp < 0 ? '-' : opts.signed && bp > 0 ? '+' : '';
  return `${sign}${txt}%`;
}

/** "12,5" (texto) → 1250 bp; aceita até 2 casas. */
export function parsePercentToBp(input: string): number | null {
  const s = input.replace('%', '').trim().replace(',', '.');
  if (!/^\d{1,3}(\.\d{1,2})?$/.test(s)) return null;
  const [i, f = ''] = s.split('.');
  return Number(i) * 100 + Number(f.padEnd(2, '0'));
}
