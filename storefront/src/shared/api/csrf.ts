import axios from 'axios';

export const XSRF_COOKIE = 'XSRF-TOKEN';

export function readXsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.split('; ').find((c) => c.startsWith(`${XSRF_COOKIE}=`));
  if (!match) return null;
  try {
    return decodeURIComponent(match.slice(XSRF_COOKIE.length + 1));
  } catch {
    return null;
  }
}

let pending: Promise<void> | null = null;

/** GET /sanctum/csrf-cookie (204) — define XSRF-TOKEN e o cookie de sessão. */
export function refreshCsrfCookie(): Promise<void> {
  if (!pending) {
    pending = axios
      .get('/sanctum/csrf-cookie', { withCredentials: true, headers: { Accept: 'application/json' } })
      .then(() => undefined)
      .finally(() => {
        pending = null;
      });
  }
  return pending;
}

/** Garante o cookie antes de uma requisição mutável (1ª visita). */
export async function ensureCsrfCookie(): Promise<void> {
  if (readXsrfToken()) return;
  try {
    await refreshCsrfCookie();
  } catch {
    /* segue sem cookie: o servidor responderá 419 e o cliente tentará de novo */
  }
}
