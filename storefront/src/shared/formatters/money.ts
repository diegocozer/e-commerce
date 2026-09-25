// UX §6.1 — Intl pt-BR; divisão por 100 apenas na exibição.
const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** 7950 → "R$ 79,50" (com espaço não separável, como o Intl gera). */
export function formatBRL(cents: number): string {
  return brl.format(cents / 100);
}

/** Leitura por extenso para aria-label: 1590 → "15 reais e 90 centavos". */
export function spokenBRL(cents: number): string {
  const abs = Math.abs(Math.trunc(cents));
  const reais = Math.floor(abs / 100);
  const cent = abs % 100;
  const parts: string[] = [];
  if (reais > 0 || cent === 0) parts.push(`${reais} ${reais === 1 ? 'real' : 'reais'}`);
  if (cent > 0) parts.push(`${cent} ${cent === 1 ? 'centavo' : 'centavos'}`);
  return (cents < 0 ? 'menos ' : '') + parts.join(' e ');
}

/**
 * "1.234,56" | "1234,56" | "1234.56" | "R$ 10" → centavos inteiros, sem parseFloat.
 * Retorna null se inválido.
 */
export function parseMoneyToCents(input: string): number | null {
  const s = input.replace(/R\$/i, '').replace(/[\s\u00a0]/g, '');
  if (!s) return null;
  let intPart = s;
  let frac = '';
  if (s.includes(',')) {
    const idx = s.lastIndexOf(',');
    intPart = s.slice(0, idx).replace(/\./g, '');
    frac = s.slice(idx + 1);
  } else if (/^\d+\.\d{1,2}$/.test(s)) {
    [intPart, frac] = s.split('.');
  } else {
    intPart = s.replace(/\./g, '');
  }
  if (!/^\d+$/.test(intPart) || !/^\d{0,2}$/.test(frac)) return null;
  return Number(intPart) * 100 + Number(frac.padEnd(2, '0'));
}
