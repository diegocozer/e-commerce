import { useCallback } from 'react';
import { hasPermission, useMe, type PermissionCheck } from './me';

/** `useCan('orders.fulfill')` — true se o admin tem a permissão (ou qualquer uma da lista). */
export function useCan(perm: PermissionCheck): boolean {
  const { data } = useMe();
  return hasPermission(data, perm);
}

/** Retorna um verificador para checar várias permissões no mesmo componente. */
export function useCanFn(): (perm: PermissionCheck) => boolean {
  const { data } = useMe();
  return useCallback((perm: PermissionCheck) => hasPermission(data, perm), [data]);
}
