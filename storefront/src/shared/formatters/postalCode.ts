export function onlyDigits(value: string): string {
  return value.replace(/\D/g, '');
}

/** "89010000" → "89010-000". */
export function formatCEP(value: string | null | undefined): string {
  const d = onlyDigits(value ?? '').slice(0, 8);
  return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
}

export function isValidCEP(value: string): boolean {
  const d = onlyDigits(value);
  return d.length === 8 && d !== '00000000';
}

/** Máscara progressiva para inputs. */
export function maskCEP(value: string): string {
  return formatCEP(value);
}
