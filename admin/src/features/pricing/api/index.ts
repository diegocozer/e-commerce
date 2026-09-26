import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { AdminVariantPickerItem, CustomerPrice, Paginated, PriceList, PriceTier } from '@/shared/api/types';

export { usePriceLists } from '@/shared/api/lookups';

export function usePriceList(id: number) {
  return useQuery({ queryKey: ['admin', 'price-lists', 'detail', id], queryFn: () => getData<PriceList>(`/admin/price-lists/${id}`) });
}
export function usePriceListTiers(id: number, params: QueryParams) {
  return useQuery({
    queryKey: ['admin', 'price-lists', 'tiers', id, params],
    queryFn: () => api.get<Paginated<PriceTier & { variant: AdminVariantPickerItem }>>(`/admin/price-lists/${id}/tiers`, params),
    placeholderData: keepPreviousData,
  });
}
export function useSavePriceList(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) => (id === null ? api.post<Envelope<PriceList>>('/admin/price-lists', body) : api.patch<Envelope<PriceList>>(`/admin/price-lists/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'price-lists'] }),
  });
}
export const deletePriceList = (id: number) => api.delete(`/admin/price-lists/${id}`);

export function useCustomerPrices(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'customer-prices', params], queryFn: () => api.get<Paginated<CustomerPrice>>('/admin/customer-prices', params), placeholderData: keepPreviousData });
}
export function useSaveCustomerPrice(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) => (id === null ? api.post<Envelope<CustomerPrice>>('/admin/customer-prices', body) : api.patch<Envelope<CustomerPrice>>(`/admin/customer-prices/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'customer-prices'] }),
  });
}
export const deleteCustomerPrice = (id: number) => api.delete(`/admin/customer-prices/${id}`);
