import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { AdminUser, Paginated, Permission, Role } from '@/shared/api/types';

export function useAdminUsers(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'users', 'list', params], queryFn: () => api.get<Paginated<AdminUser>>('/admin/users', params), placeholderData: keepPreviousData });
}
export function useRoles() {
  return useQuery({ queryKey: ['admin', 'roles'], queryFn: () => getData<Role[]>('/admin/roles') });
}
export function usePermissions() {
  return useQuery({ queryKey: ['admin', 'permissions'], queryFn: () => getData<Permission[]>('/admin/permissions'), staleTime: 10 * 60_000 });
}

function useInvalidating<V, R>(fn: (v: V) => Promise<R>, keys: string[][]) {
  const qc = useQueryClient();
  return useMutation({ mutationFn: fn, onSuccess: () => keys.forEach((k) => void qc.invalidateQueries({ queryKey: k })) });
}

export const useSaveUser = (id: number | null) =>
  useInvalidating((body: { name: string; email: string; roles: string[] }) => (id === null ? api.post<Envelope<AdminUser>>('/admin/users', body) : api.patch<Envelope<AdminUser>>(`/admin/users/${id}`, body)), [['admin', 'users'], ['admin', 'roles']]);
export const useUserAction = () =>
  useInvalidating(({ id, action }: { id: number; action: 'activate' | 'deactivate' | 'password-reset' }) => api.post<unknown>(`/admin/users/${id}/${action}`), [['admin', 'users']]);
export const deleteUser = (id: number) => api.delete(`/admin/users/${id}`);

export const useSaveRole = (id: number | null) =>
  useInvalidating((body: { name?: string; permissions: string[] }) => (id === null ? api.post<Envelope<Role>>('/admin/roles', body) : api.patch<Envelope<Role>>(`/admin/roles/${id}`, body)), [['admin', 'roles'], ['admin', 'me']]);
export const deleteRole = (id: number) => api.delete(`/admin/roles/${id}`);
