import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope, type QueryParams } from '@/shared/api/client';
import type { AdminBrand, Paginated } from '@/shared/api/types';

export function useBrands(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'brands', 'list', params], queryFn: () => api.get<Paginated<AdminBrand>>('/admin/brands', params), placeholderData: keepPreviousData });
}

export function useSaveBrand(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { name: string; slug?: string; is_active: boolean }) => (id === null ? api.post<Envelope<AdminBrand>>('/admin/brands', body) : api.patch<Envelope<AdminBrand>>(`/admin/brands/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'brands'] }),
  });
}

export const deleteBrand = (id: number) => api.delete(`/admin/brands/${id}`);
export function uploadBrandLogo(id: number, file: File) {
  const fd = new FormData();
  fd.append('file', file);
  return api.upload<Envelope<AdminBrand>>(`/admin/brands/${id}/logo`, fd);
}
