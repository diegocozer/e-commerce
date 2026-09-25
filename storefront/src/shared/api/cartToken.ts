import { storageGet, storageRemove, storageSet } from '../lib/storage';

// API §1.3: token do carrinho de visitante em localStorage `cv_cart_token`.
export const CART_TOKEN_KEY = 'cv_cart_token';
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function getCartToken(): string | null {
  const t = storageGet(CART_TOKEN_KEY);
  return t && UUID_RE.test(t) ? t : null;
}

export function setCartToken(token: string | null | undefined): void {
  if (token && UUID_RE.test(token)) storageSet(CART_TOKEN_KEY, token);
}

export function clearCartToken(): void {
  storageRemove(CART_TOKEN_KEY);
}

/** Endpoints que aceitam o header X-Cart-Token (§1.3). */
export function acceptsCartToken(url: string): boolean {
  const path = url.split('?')[0];
  return path === '/cart' || path.startsWith('/cart/') || path === '/auth/login' || path === '/auth/register';
}
