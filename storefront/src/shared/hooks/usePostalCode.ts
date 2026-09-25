import { useCallback, useSyncExternalStore } from 'react';
import { storageGet, storageRemove, storageSet } from '../lib/storage';
import { isValidCEP, onlyDigits } from '../formatters/postalCode';

// CEP preferido (chip do header) — pré-preenche frete no produto, carrinho e checkout.
const KEY = 'cv_postal_code';
const listeners = new Set<() => void>();

function read(): string | null {
  const v = storageGet(KEY);
  return v && isValidCEP(v) ? v : null;
}

let current = read();

function subscribe(cb: () => void) {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

export function setPreferredPostalCode(cep: string | null): void {
  const digits = cep ? onlyDigits(cep) : '';
  if (digits && isValidCEP(digits)) {
    storageSet(KEY, digits);
    current = digits;
  } else {
    storageRemove(KEY);
    current = null;
  }
  listeners.forEach((l) => l());
}

export function usePreferredPostalCode(): [string | null, (cep: string | null) => void] {
  const value = useSyncExternalStore(subscribe, () => current, () => null);
  const set = useCallback((cep: string | null) => setPreferredPostalCode(cep), []);
  return [value, set];
}
