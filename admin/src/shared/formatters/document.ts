export const onlyDigits = (s: string): string => s.replace(/\D/g, '');

export function formatCEP(cep: string | null | undefined): string {
  if (!cep) return '—';
  const d = onlyDigits(cep);
  return d.length === 8 ? `${d.slice(0, 5)}-${d.slice(5)}` : cep;
}

export function formatCPF(cpf: string | null | undefined): string {
  if (!cpf) return '—';
  const d = onlyDigits(cpf);
  if (d.length !== 11) return cpf;
  return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
}

/** CNPJ numérico ou alfanumérico (ADR-026a). */
export function formatCNPJ(cnpj: string | null | undefined): string {
  if (!cnpj) return '—';
  const d = cnpj.toUpperCase().replace(/[^0-9A-Z]/g, '');
  if (d.length !== 14) return cnpj;
  return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`;
}

export function formatDocument(doc: string | null | undefined): string {
  if (!doc) return '—';
  if (doc.includes('*')) return doc;
  const clean = doc.toUpperCase().replace(/[^0-9A-Z]/g, '');
  return clean.length === 11 ? formatCPF(clean) : clean.length === 14 ? formatCNPJ(clean) : doc;
}

export function formatPhone(phone: string | null | undefined): string {
  if (!phone) return '—';
  const d = onlyDigits(phone);
  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return phone;
}

/** Máscara progressiva de CEP para inputs. */
export function maskCEP(input: string): string {
  const d = onlyDigits(input).slice(0, 8);
  return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
}
