import { useQuery } from '@tanstack/react-query';
import { request, type Envelope } from '@/shared/api/client';
import { isApiError } from '@/shared/api/errors';
import type { AdminMe, PermissionName } from '@/shared/api/types';

export const meKey = ['admin', 'me'] as const;

export async function fetchMe(): Promise<AdminMe | null> {
  try {
    const res = await request<Envelope<AdminMe>>('GET', '/admin/me', { silent: true });
    return res.data;
  } catch (e) {
    if (isApiError(e) && e.status === 401) return null;
    throw e;
  }
}

/** Sessão do admin (`GET /admin/me`). `null` = não autenticado. */
export function useMe() {
  return useQuery({ queryKey: meKey, queryFn: fetchMe, retry: false, staleTime: 5 * 60_000 });
}

export type PermissionCheck = PermissionName | readonly PermissionName[];

/** Checagem pura: lista = qualquer uma basta (`A | B`, API §6.3). */
export function hasPermission(me: AdminMe | null | undefined, perm: PermissionCheck): boolean {
  if (!me) return false;
  if (me.is_super_admin) return true;
  const list = typeof perm === 'string' ? [perm] : perm;
  if (list.length === 0) return true;
  return list.some((p) => me.permissions.includes(p));
}
