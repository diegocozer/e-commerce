import axios, { AxiosHeaders, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
import { uuidv4 } from '../lib/uuid';
import { updateServerClock } from '../lib/serverClock';
import { acceptsCartToken, clearCartToken, getCartToken, setCartToken } from './cartToken';
import { ensureCsrfCookie, readXsrfToken, refreshCsrfCookie } from './csrf';
import { toApiError } from './errors';

// Cliente HTTP único (API §1.2/§1.3): cookies de sessão Sanctum (withCredentials),
// X-XSRF-TOKEN lido do cookie, X-Cart-Token nas rotas de carrinho/login/cadastro,
// X-Request-Id por requisição; 419 → renova CSRF e repete uma vez; 404 cart_not_found →
// apaga o token local e repete sem o header. Todo erro sai como ApiError.

export const API_BASE = '/api/v1';
const MUTATING = new Set(['post', 'put', 'patch', 'delete']);

interface RetryFlags {
  _csrfRetried?: boolean;
  _cartRetried?: boolean;
  /** Não enviar X-Cart-Token nesta requisição. */
  _skipCartToken?: boolean;
}
type Config = InternalAxiosRequestConfig & RetryFlags;

export const CART_EXPIRED_EVENT = 'cv:cart-expired';
export const UNAUTHENTICATED_EVENT = 'cv:unauthenticated';

export const http = axios.create({
  baseURL: API_BASE,
  withCredentials: true,
  // Tratamos o XSRF manualmente (abaixo) para controlar o fluxo 419.
  withXSRFToken: false,
  xsrfCookieName: undefined,
  timeout: 30000,
  headers: { Accept: 'application/json' },
});

http.interceptors.request.use(async (config: Config) => {
  const headers = AxiosHeaders.from(config.headers);
  const method = (config.method ?? 'get').toLowerCase();
  if (MUTATING.has(method)) {
    await ensureCsrfCookie();
    const token = readXsrfToken();
    if (token) headers.set('X-XSRF-TOKEN', token);
  }
  const url = config.url ?? '';
  if (!config._skipCartToken && acceptsCartToken(url)) {
    const cartToken = getCartToken();
    if (cartToken) headers.set('X-Cart-Token', cartToken);
  }
  if (!headers.has('X-Request-Id')) headers.set('X-Request-Id', uuidv4());
  config.headers = headers;
  return config;
});

http.interceptors.response.use(
  (response) => {
    updateServerClock(response.headers['date'] as string | undefined);
    const newToken = response.headers['x-cart-token'];
    if (typeof newToken === 'string' && newToken) setCartToken(newToken);
    return response;
  },
  async (error: unknown) => {
    if (axios.isAxiosError(error) && error.config) {
      const config = error.config as Config;
      const status = error.response?.status;
      const body = error.response?.data as { code?: string } | undefined;
      updateServerClock(error.response?.headers?.['date'] as string | undefined);

      if (status === 419 && !config._csrfRetried) {
        config._csrfRetried = true;
        await refreshCsrfCookie().catch(() => undefined);
        return http.request(config);
      }
      if (status === 404 && body?.code === 'cart_not_found' && !config._cartRetried) {
        clearCartToken();
        config._cartRetried = true;
        config._skipCartToken = true;
        const headers = AxiosHeaders.from(config.headers);
        headers.delete('X-Cart-Token');
        config.headers = headers;
        if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent(CART_EXPIRED_EVENT));
        return http.request(config);
      }
      if (status === 401 && typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent(UNAUTHENTICATED_EVENT, { detail: { url: config.url } }));
      }
    }
    return Promise.reject(toApiError(error));
  },
);

/** Helpers que desembrulham o envelope `{data}`. */
export async function getData<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.get<{ data: T }>(url, config);
  return res.data.data;
}

export async function postData<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.post<{ data: T }>(url, body ?? {}, config);
  return res.data.data;
}

export async function patchData<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.patch<{ data: T }>(url, body ?? {}, config);
  return res.data.data;
}

export async function putData<T>(url: string, body?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.put<{ data: T }>(url, body ?? {}, config);
  return res.data.data;
}

export async function deleteData<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.delete<{ data: T }>(url, config);
  return res.data.data;
}

/** Para respostas 204. */
export async function send(method: 'post' | 'put' | 'delete', url: string, body?: unknown): Promise<void> {
  await http.request({ method, url, data: body ?? (method === 'delete' ? undefined : {}) });
}
