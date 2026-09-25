// Acesso a Web Storage sempre protegido: pode lançar em aba privada, com
// armazenamento bloqueado ou em previews. Nunca é fonte de verdade.
type Area = 'local' | 'session';

function area(kind: Area): Storage | null {
  try {
    return kind === 'local' ? window.localStorage : window.sessionStorage;
  } catch {
    return null;
  }
}

export function storageGet(key: string, kind: Area = 'local'): string | null {
  try {
    return area(kind)?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

export function storageSet(key: string, value: string, kind: Area = 'local'): void {
  try {
    area(kind)?.setItem(key, value);
  } catch {
    /* armazenamento indisponível: ignora */
  }
}

export function storageRemove(key: string, kind: Area = 'local'): void {
  try {
    area(kind)?.removeItem(key);
  } catch {
    /* ignora */
  }
}

export function storageGetJson<T>(key: string, kind: Area = 'local'): T | null {
  const raw = storageGet(key, kind);
  if (raw === null) return null;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return null;
  }
}

export function storageSetJson(key: string, value: unknown, kind: Area = 'local'): void {
  storageSet(key, JSON.stringify(value), kind);
}
