import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { Coupon, CouponRedemption, Paginated, Promotion } from '@/shared/api/types';

export function usePromotions(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'promotions', 'list', params], queryFn: () => api.get<Paginated<Promotion>>('/admin/promotions', params), placeholderData: keepPreviousData });
}
export function useSavePromotion(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) => (id === null ? api.post<Envelope<Promotion>>('/admin/promotions', body) : api.patch<Envelope<Promotion>>(`/admin/promotions/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'promotions'] }),
  });
}
export const deletePromotion = (id: number) => api.delete(`/admin/promotions/${id}`);
export const previewPromotion = (id: number, variantIds: number[]) =>
  getDataPost<{ variant_id: number; sku: string; base_price_cents: number; promo_price_cents: number }[]>(`/admin/promotions/${id}/preview`, { variant_ids: variantIds });

async function getDataPost<T>(path: string, body: unknown): Promise<T> {
  return (await api.post<Envelope<T>>(path, body)).data;
}

export function useCoupons(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'coupons', 'list', params], queryFn: () => api.get<Paginated<Coupon>>('/admin/coupons', params), placeholderData: keepPreviousData });
}
export function useCouponRedemptions(id: number) {
  return useQuery({ queryKey: ['admin', 'coupons', 'redemptions', id, {}], queryFn: () => api.get<Paginated<CouponRedemption>>(`/admin/coupons/${id}/redemptions`, { per_page: 50 }) });
}
export function useSaveCoupon(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) => (id === null ? api.post<Envelope<Coupon>>('/admin/coupons', body) : api.patch<Envelope<Coupon>>(`/admin/coupons/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'coupons'] }),
  });
}
export const deleteCoupon = (id: number) => api.delete(`/admin/coupons/${id}`);
export const generateCouponCode = () => getDataPost<{ code: string }>('/admin/coupons/generate-code', {});
export { getData };
