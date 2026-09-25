import { onlyDigits } from './postalCode';

/** "47999990001" → "(47) 99999-0001"; 10 dígitos → "(47) 3333-0000". */
export function formatPhone(value: string | null | undefined): string {
  const d = onlyDigits(value ?? '').slice(0, 11);
  if (d.length <= 2) return d.length ? `(${d}` : '';
  const ddd = d.slice(0, 2);
  const rest = d.slice(2);
  if (d.length <= 6) return `(${ddd}) ${rest}`;
  if (d.length <= 10) return `(${ddd}) ${rest.slice(0, 4)}-${rest.slice(4)}`;
  return `(${ddd}) ${rest.slice(0, 5)}-${rest.slice(5)}`;
}

export const maskPhone = formatPhone;

export function isValidPhone(value: string): boolean {
  const d = onlyDigits(value);
  return d.length === 10 || d.length === 11;
}
