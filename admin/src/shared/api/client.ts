import { ApiError } from './errors';

export const API_BASE = '/api/v1';

export type QueryValue = string | number | boolean | null | undefined | readonly (string | number)[];
export type QueryParams = Record<string, QueryValue>;

/** Serializa filtros conforme API §1.4: listas por vírgula, booleanos 1/0, vazios omitidos. */
export function buildQuery(params: QueryParams | undefined): string {
  if (!params) return '';
  const sp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    if (Array.isArray(v)) {
      if (v.length) sp.set(k, v.join(','));
    } else if (typeof v === 'boolean') {
      sp.set(k, v ? '1' : '0');
    } else {
      sp.set(k, String(v));
    }
  }
  const s = sp.toString();
  return s ? `?${s}` : '';
}

type Listener<T> = (arg: T) => void;
let onUnauthorized: Listener<ApiError> | null = null;
let onForbidden: Listener<ApiError> | null = null;

/** Registrado pelo App: 401 fora do bootstrap ⇒ redireciona para o login com `redirect`. */
export function setUnauthorizedHandler(fn: Listener<ApiError> | null): void {
  onUnauthorized = fn;
}
/** Registrado pelo App: 403 em ação ⇒ snackbar + refetch das permissões (UX §5.12). */
export function setForbiddenHandler(fn: Listener<ApiError> | null): void {
  onForbidden = fn;
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

function absolute(url: string): string {
  return typeof window !== 'undefined' ? new URL(url, window.location.origin).toString() : url;
}

let csrfPromise: Promise<void> | null = null;
/** GET /sanctum/csrf-cookie → cookie XSRF-TOKEN (API §1.2). */
export function ensureCsrf(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return Promise.resolve();
  if (!csrfPromise) {
    csrfPromise = fetch(absolute('/sanctum/csrf-cookie'), {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
      .then(() => undefined)
      .catch(() => undefined)
      .finally(() => {
        csrfPromise = null;
      });
  }
  return csrfPromise;
}

const SKIP_UNAUTHORIZED = [/^\/admin\/me$/, /^\/admin\/auth\//];

export interface RequestOptions {
  query?: QueryParams;
  body?: unknown;
  signal?: AbortSignal;
  /** Não disparar o handler global de 401/403 (ex.: bootstrap). */
  silent?: boolean;
}

async function parseError(res: Response, path: string, silent: boolean): Promise<ApiError> {
  let body: unknown = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }
  const obj = (body && typeof body === 'object' ? body : {}) as Record<string, unknown>;
  const retry = res.headers.get('Retry-After');
  const err = new ApiError({
    status: res.status,
    message: typeof obj.message === 'string' ? obj.message : res.statusText || 'Erro',
    code: typeof obj.code === 'string' ? obj.code : undefined,
    fieldErrors:
      res.status === 422 && obj.errors && typeof obj.errors === 'object'
        ? (obj.errors as Record<string, string[]>)
        : undefined,
    body,
    requestId: res.headers.get('X-Request-Id'),
    retryAfter: retry ? Number(retry) : null,
  });
  if (!silent && !SKIP_UNAUTHORIZED.some((r) => r.test(path))) {
    if (res.status === 401) onUnauthorized?.(err);
    if (res.status === 403 && err.code === 'forbidden') onForbidden?.(err);
  }
  return err;
}

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
// jsdom (testes) tem AbortSignal incompatível com o fetch do Node — só repassamos no navegador.
const PASS_SIGNAL = import.meta.env.MODE !== 'test';

async function send(method: string, path: string, opts: RequestOptions, retried = false): Promise<Response> {
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  if (MUTATING.has(method)) await ensureCsrf();
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const xsrf = readCookie('XSRF-TOKEN');
  if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
  let res: Response;
  try {
    res = await fetch(absolute(`${API_BASE}${path}${buildQuery(opts.query)}`), {
      method,
      credentials: 'include',
      headers,
      body: opts.body === undefined ? undefined : isForm ? (opts.body as FormData) : JSON.stringify(opts.body),
      signal: PASS_SIGNAL ? opts.signal : undefined,
    });
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') throw e;
    throw new ApiError({ status: 0, message: 'Sem conexão com o servidor.', code: 'network_error' });
  }
  // 419: renova CSRF e repete uma vez (API §1.2 item 4).
  if (res.status === 419 && !retried) {
    await ensureCsrf(true);
    return send(method, path, opts, true);
  }
  return res;
}

export async function request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<T> {
  const res = await send(method, path, opts);
  if (!res.ok) throw await parseError(res, path, !!opts.silent);
  if (res.status === 204) return undefined as T;
  const text = await res.text();
  return (text ? JSON.parse(text) : undefined) as T;
}

/** Envelope `{ data }` da API. */
export interface Envelope<T> {
  data: T;
}

export const api = {
  get: <T>(path: string, query?: QueryParams, signal?: AbortSignal) => request<T>('GET', path, { query, signal }),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, { body: body ?? {} }),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body: body ?? {} }),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, { body: body ?? {} }),
  delete: <T = void>(path: string) => request<T>('DELETE', path),
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, { body: form }),
};

/** GET + desembrulha `data`. */
export async function getData<T>(path: string, query?: QueryParams, signal?: AbortSignal): Promise<T> {
  const res = await api.get<Envelope<T>>(path, query, signal);
  return res.data;
}

/** Download de CSV (Content-Disposition: attachment) preservando sessão e erros JSON. */
export async function downloadFile(path: string, query: QueryParams, fallbackName: string): Promise<string> {
  const res = await send('GET', path, { query });
  if (!res.ok) throw await parseError(res, path, false);
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') ?? '';
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
  const filename = match ? decodeURIComponent(match[1]) : fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return filename;
}
