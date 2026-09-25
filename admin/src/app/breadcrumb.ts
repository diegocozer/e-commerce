import { useEffect, useSyncExternalStore } from 'react';

let current: string | null = null;
const listeners = new Set<() => void>();
const set = (v: string | null) => {
  current = v;
  listeners.forEach((l) => l());
};

/** Página de detalhe define o último item do breadcrumb ("CV-000123"). */
export function useBreadcrumb(label: string | null | undefined): void {
  useEffect(() => {
    set(label ?? null);
    return () => set(null);
  }, [label]);
}

export function useBreadcrumbLabel(): string | null {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => current,
    () => current,
  );
}
