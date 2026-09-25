import { HttpResponse } from 'msw';
import type { Paginated } from '@/shared/api/types';

export const B = '*/api/v1';

export function paginate<T>(rows: T[], url: URL): Paginated<T> {
  const page = Math.max(1, Number(url.searchParams.get('page') ?? 1));
  const per = Math.min(100, Number(url.searchParams.get('per_page') ?? 25));
  const total = rows.length;
  const last = Math.max(1, Math.ceil(total / per));
  const data = rows.slice((page - 1) * per, page * per);
  return {
    data,
    links: { first: '?page=1', last: `?page=${last}`, prev: page > 1 ? `?page=${page - 1}` : null, next: page < last ? `?page=${page + 1}` : null },
    meta: { current_page: page, from: total ? (page - 1) * per + 1 : null, last_page: last, path: url.pathname, per_page: per, to: total ? (page - 1) * per + data.length : null, total, links: [] },
  };
}

export const ok = <T>(data: T, status = 200) => HttpResponse.json({ data }, { status });
export const noContent = () => new HttpResponse(null, { status: 204 });
export const notFound = () => HttpResponse.json({ message: 'Registro não encontrado.', code: 'not_found' }, { status: 404 });
export const invalid = (errors: Record<string, string[]>) =>
  HttpResponse.json({ message: Object.values(errors)[0]?.[0] ?? 'Dados inválidos.', errors }, { status: 422 });
export const conflict = (code: string, message: string, extra: Record<string, unknown> = {}) => HttpResponse.json({ message, code, ...extra }, { status: 409 });

export function textMatch(q: string | null, ...fields: (string | null | undefined)[]): boolean {
  if (!q) return true;
  const needle = q.toLowerCase();
  return fields.some((f) => (f ?? '').toLowerCase().includes(needle));
}

export function sortBy<T>(rows: T[], sort: string | null, map: Record<string, (r: T) => string | number>): T[] {
  if (!sort) return rows;
  const desc = sort.startsWith('-');
  const key = map[sort.replace(/^-/, '')];
  if (!key) return rows;
  return [...rows].sort((a, b) => {
    const va = key(a);
    const vb = key(b);
    const c = va < vb ? -1 : va > vb ? 1 : 0;
    return desc ? -c : c;
  });
}

export async function body<T>(req: Request): Promise<T> {
  return (await req.json()) as T;
}

export const now = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
