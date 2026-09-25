import { onlyDigits } from './postalCode';

// CPF / CNPJ (inclui CNPJ alfanumérico — ADR-026a; API §1.5: ^[0-9A-Z]{12}[0-9]{2}$).

/** Remove máscara do CNPJ preservando letras (maiúsculas). */
export function normalizeCNPJ(value: string): string {
  return value.toUpperCase().replace(/[^0-9A-Z]/g, '');
}

export function isValidCPF(value: string): boolean {
  const cpf = onlyDigits(value);
  if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
  const digits = cpf.split('').map(Number);
  for (const len of [9, 10]) {
    let sum = 0;
    for (let i = 0; i < len; i++) sum += digits[i] * (len + 1 - i);
    const dv = ((sum * 10) % 11) % 10;
    if (dv !== digits[len]) return false;
  }
  return true;
}

function cnpjCharValue(ch: string): number {
  return ch.charCodeAt(0) - 48; // '0'..'9' → 0..9; 'A' → 17 … 'Z' → 42
}

function cnpjCheckDigit(base: string): number {
  const weights = base.length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  let sum = 0;
  for (let i = 0; i < base.length; i++) sum += cnpjCharValue(base[i]) * weights[i];
  const rest = sum % 11;
  return rest < 2 ? 0 : 11 - rest;
}

export function isValidCNPJ(value: string): boolean {
  const cnpj = normalizeCNPJ(value);
  if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(cnpj)) return false;
  if (/^(.)\1{13}$/.test(cnpj)) return false;
  const dv1 = cnpjCheckDigit(cnpj.slice(0, 12));
  const dv2 = cnpjCheckDigit(cnpj.slice(0, 12) + String(dv1));
  return cnpj.slice(12) === `${dv1}${dv2}`;
}

/** "52998224725" → "529.982.247-25" (máscara progressiva). */
export function formatCPF(value: string | null | undefined): string {
  const d = onlyDigits(value ?? '').slice(0, 11);
  if (d.length <= 3) return d;
  if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
  if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;
  return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
}

/** "12345678000195" → "12.345.678/0001-95"; alfanumérico "12ABC34501DE35" → "12.ABC.345/01DE-35". */
export function formatCNPJ(value: string | null | undefined): string {
  const c = normalizeCNPJ(value ?? '').slice(0, 14);
  if (c.length <= 2) return c;
  if (c.length <= 5) return `${c.slice(0, 2)}.${c.slice(2)}`;
  if (c.length <= 8) return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5)}`;
  if (c.length <= 12) return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5, 8)}/${c.slice(8)}`;
  return `${c.slice(0, 2)}.${c.slice(2, 5)}.${c.slice(5, 8)}/${c.slice(8, 12)}-${c.slice(12)}`;
}

/** Formata documento de faturamento (CPF 11 / CNPJ 14). */
export function formatDocument(value: string): string {
  const n = normalizeCNPJ(value);
  return n.length === 11 && /^\d+$/.test(n) ? formatCPF(n) : formatCNPJ(n);
}
