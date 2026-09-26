import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { BulkProductAction, BulkResult } from '@/shared/api/extraTypes';
import type { AdminProduct, AdminProductImage, AdminProductListItem, Paginated, PriceList, PriceTier } from '@/shared/api/types';

export const productKeys = {
  all: ['admin', 'products'] as const,
  list: (p: QueryParams) => ['admin', 'products', 'list', p] as const,
  detail: (id: number) => ['admin', 'products', 'detail', id] as const,
  tiers: (variantId: number) => ['admin', 'price-tiers', variantId] as const,
};

export function useProducts(params: QueryParams) {
  return useQuery({ queryKey: productKeys.list(params), queryFn: () => api.get<Paginated<AdminProductListItem>>('/admin/products', params), placeholderData: keepPreviousData });
}

export function useProduct(id: number | null) {
  return useQuery({ queryKey: productKeys.detail(id ?? 0), queryFn: () => getData<AdminProduct>(`/admin/products/${id}`), enabled: id !== null });
}

export type ProductPayload = Record<string, unknown>;

export function useSaveProduct(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: ProductPayload) =>
      id === null ? api.post<Envelope<AdminProduct>>('/admin/products', body) : api.patch<Envelope<AdminProduct>>(`/admin/products/${id}`, body),
    onSuccess: (res) => {
      qc.setQueryData(productKeys.detail(res.data.id), res.data);
      void qc.invalidateQueries({ queryKey: ['admin', 'products', 'list'] });
      void qc.invalidateQueries({ queryKey: ['admin', 'inventory'] });
    },
  });
}

export function useBulkProducts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { ids: number[]; action: BulkProductAction; category_id?: number }) => api.post<Envelope<BulkResult>>('/admin/products/bulk', body).then((r) => r.data),
    onSuccess: () => void qc.invalidateQueries({ queryKey: productKeys.all }),
  });
}

export const deleteProduct = (id: number) => api.delete(`/admin/products/${id}`);
export const deleteVariant = (productId: number, variantId: number) => api.delete(`/admin/products/${productId}/variants/${variantId}`);

export const checkSlug = (slug: string, ignoreId: number | null) =>
  getData<{ available: boolean; suggestion: string | null }>('/admin/products/slug-availability', { slug, ignore_id: ignoreId ?? undefined });
export const checkSku = (sku: string, ignoreId: number | null) =>
  getData<{ available: boolean; used_by: { product_id: number; product_name: string } | null }>('/admin/variants/sku-availability', { sku, ignore_id: ignoreId ?? undefined });

// Imagens
export const uploadImage = (productId: number, file: File, alt?: string) => {
  const fd = new FormData();
  fd.append('file', file);
  if (alt) fd.append('alt', alt);
  return api.upload<Envelope<AdminProductImage>>(`/admin/products/${productId}/images`, fd).then((r) => r.data);
};
export const updateImage = (productId: number, imageId: number, body: { alt?: string; variant_id?: number | null }) =>
  api.patch<Envelope<AdminProductImage>>(`/admin/products/${productId}/images/${imageId}`, body).then((r) => r.data);
export const deleteImage = (productId: number, imageId: number) => api.delete(`/admin/products/${productId}/images/${imageId}`);
export const reorderImages = (productId: number, ids: number[]) =>
  api.post<Envelope<AdminProductImage[]>>(`/admin/products/${productId}/images/reorder`, { ids }).then((r) => r.data);

// Faixas de preço
export interface VariantTiers {
  base: PriceTier[];
  price_lists: { price_list: PriceList; tiers: PriceTier[] }[];
}
export function usePriceTiers(variantId: number | null) {
  return useQuery({ queryKey: productKeys.tiers(variantId ?? 0), queryFn: () => getData<VariantTiers>(`/admin/variants/${variantId}/price-tiers`), enabled: variantId !== null });
}
export function useSaveTiers(variantId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { price_list_id: number | null; tiers: { min_quantity: number; price_cents: number }[] }) =>
      api.put<Envelope<VariantTiers>>(`/admin/variants/${variantId}/price-tiers`, body).then((r) => r.data),
    onSuccess: (data) => qc.setQueryData(productKeys.tiers(variantId), data),
  });
}
