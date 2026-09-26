import { parseDecimal } from '@/shared/formatters/quantity';

/** "10,5" kg → 10500 g (kg com 3 casas = gramas exatos). */
export function kgToGrams(kg: string): number | null {
  const n = parseDecimal(kg, 3);
  if (n === null) return null;
  const [i, f = ''] = n.split('.');
  return Number(i) * 1000 + Number(f.padEnd(3, '0'));
}
export const gramsToKg = (g: number | null) => (g === null ? '' : String(g / 1000));

/** "0,5" m³ → 500000 cm³ (até 6 casas = cm³ exatos). */
export function m3ToCm3(m3: string): number | null {
  const n = parseDecimal(m3, 6);
  if (n === null) return null;
  const [i, f = ''] = n.split('.');
  return Number(i) * 1_000_000 + Number(f.padEnd(6, '0'));
}
export const cm3ToM3 = (cm3: number | null) => (cm3 === null ? '' : String(cm3 / 1_000_000));
