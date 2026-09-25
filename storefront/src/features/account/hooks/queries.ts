import { useQuery } from '@tanstack/react-query';
import { useAuth } from '@/features/auth';
import type { OrderStatus } from '@/shared/api/types';
import { accountKeys, fetchAddresses, fetchOrder, fetchOrders, fetchReorderSuggestions } from '../api';

export function useAddresses() {
  return useQuery({ queryKey: accountKeys.addresses, queryFn: fetchAddresses });
}

export function useOrders(page: number, status: OrderStatus[] | null, perPage = 10) {
  return useQuery({
    queryKey: [...accountKeys.orders({ page, status: status?.join(',') ?? null }), perPage],
    queryFn: () => fetchOrders({ page, status, per_page: perPage }),
  });
}

export function useOrder(uuid: string) {
  return useQuery({ queryKey: accountKeys.order(uuid), queryFn: () => fetchOrder(uuid), retry: false });
}

export function useReorderSuggestions() {
  const { isAuthenticated } = useAuth();
  return useQuery({ queryKey: accountKeys.reorderSuggestions, queryFn: () => fetchReorderSuggestions(6), enabled: isAuthenticated });
}
