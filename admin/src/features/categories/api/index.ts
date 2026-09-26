import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, type Envelope } from '@/shared/api/client';
import type { AdminCategory } from '@/shared/api/types';

export { useCategoryTree } from '@/shared/api/lookups';

const treeKey = ['admin', 'categories'] as const;

export function useSaveCategory(id: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) => (id === null ? api.post<Envelope<AdminCategory>>('/admin/categories', body) : api.patch<Envelope<AdminCategory>>(`/admin/categories/${id}`, body)),
    onSuccess: () => void qc.invalidateQueries({ queryKey: treeKey }),
  });
}

export function useReorderCategories() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { parent_id: number | null; ids: number[] }) => api.post<void>('/admin/categories/reorder', body),
    onSettled: () => void qc.invalidateQueries({ queryKey: treeKey }),
  });
}

export const deleteCategory = (id: number) => api.delete(`/admin/categories/${id}`);
