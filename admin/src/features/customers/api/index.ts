import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { AdminCompany, AdminCustomer, Paginated } from '@/shared/api/types';

export function useCustomers(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'customers', 'list', params], queryFn: () => api.get<Paginated<AdminCustomer>>('/admin/customers', params), placeholderData: keepPreviousData });
}
export function useCustomer(id: number) {
  return useQuery({ queryKey: ['admin', 'customers', 'detail', id], queryFn: () => getData<AdminCustomer>(`/admin/customers/${id}`) });
}
export function useCompanies(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'companies', 'list', params], queryFn: () => api.get<Paginated<AdminCompany>>('/admin/companies', params), placeholderData: keepPreviousData });
}
export function useCompany(id: number) {
  return useQuery({ queryKey: ['admin', 'companies', 'detail', id], queryFn: () => getData<AdminCompany>(`/admin/companies/${id}`) });
}

function useCustomerMutation<V>(fn: (v: V) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'customers'] });
      void qc.invalidateQueries({ queryKey: ['admin', 'companies'] });
    },
  });
}

export const usePatchCustomer = (id: number) => useCustomerMutation((body: Record<string, unknown>) => api.patch<Envelope<AdminCustomer>>(`/admin/customers/${id}`, body));
export const useBlockCustomer = (id: number) => useCustomerMutation((reason: string) => api.post<Envelope<AdminCustomer>>(`/admin/customers/${id}/block`, { reason }));
export const useUnblockCustomer = (id: number) => useCustomerMutation(() => api.post<Envelope<AdminCustomer>>(`/admin/customers/${id}/unblock`));
export const useCustomerPasswordReset = (id: number) => useMutation({ mutationFn: () => api.post<Envelope<{ message: string }>>(`/admin/customers/${id}/password-reset`) });
export const useAnonymizeCustomer = (id: number) => useCustomerMutation(() => api.post<Envelope<AdminCustomer>>(`/admin/customers/${id}/anonymize`, { confirm: true }));
export const useRevealCustomerDocument = (id: number) =>
  useMutation({ mutationFn: () => api.post<Envelope<{ cpf: string | null; cnpj: string | null }>>(`/admin/customers/${id}/reveal-document`).then((r) => r.data) });
export const usePatchCompany = (id: number) => useCustomerMutation((body: Record<string, unknown>) => api.patch<Envelope<AdminCompany>>(`/admin/companies/${id}`, body));
