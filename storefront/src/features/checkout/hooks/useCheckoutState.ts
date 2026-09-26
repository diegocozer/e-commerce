import { useCallback, useState } from 'react';
import type { ShippingQuote } from '@/shared/api/types';
import { storageGetJson, storageRemove, storageSetJson } from '@/shared/lib/storage';
import { uuidv4 } from '@/shared/lib/uuid';

export const CHECKOUT_STATE_KEY = 'cv_checkout';
export const IDEMPOTENCY_KEY = 'cv_checkout_idempotency';

export interface CheckoutState {
  addressUuid: string | null;
  quote: ShippingQuote | null;
  optionId: string | null;
  paymentConfirmed: boolean;
  notes: string;
}

const EMPTY: CheckoutState = { addressUuid: null, quote: null, optionId: null, paymentConfirmed: false, notes: '' };

export function useCheckoutState() {
  const [state, setState] = useState<CheckoutState>(() => ({ ...EMPTY, ...(storageGetJson<CheckoutState>(CHECKOUT_STATE_KEY, 'session') ?? {}) }));
  const update = useCallback((patch: Partial<CheckoutState> | ((s: CheckoutState) => Partial<CheckoutState>)) => {
    setState((s) => {
      const next = { ...s, ...(typeof patch === 'function' ? patch(s) : patch) };
      storageSetJson(CHECKOUT_STATE_KEY, next, 'session');
      return next;
    });
  }, []);
  const reset = useCallback(() => {
    storageRemove(CHECKOUT_STATE_KEY, 'session');
    storageRemove(IDEMPOTENCY_KEY, 'session');
    setState(EMPTY);
  }, []);
  return { state, update, reset };
}

/**
 * Uma Idempotency-Key por tentativa de confirmação (UX §4.6.6 / API §1.9): reutilizada
 * enquanto o corpo (fingerprint) for o mesmo — duplo clique, retry, refresh; nova chave
 * quando carrinho/endereço/frete/total mudarem.
 */
export function idempotencyKeyFor(fingerprint: string): string {
  const stored = storageGetJson<{ fingerprint: string; key: string }>(IDEMPOTENCY_KEY, 'session');
  if (stored && stored.fingerprint === fingerprint) return stored.key;
  const key = uuidv4();
  storageSetJson(IDEMPOTENCY_KEY, { fingerprint, key }, 'session');
  return key;
}

export function resetIdempotencyKey(): void {
  storageRemove(IDEMPOTENCY_KEY, 'session');
}

export function isQuoteExpired(quote: ShippingQuote | null, now = Date.now()): boolean {
  if (!quote?.expires_at) return true;
  return Date.parse(quote.expires_at) <= now;
}
