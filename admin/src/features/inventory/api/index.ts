import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, downloadFile, type Envelope, type QueryParams } from '@/shared/api/client';
import type { InventoryItem, InventoryMovement, Paginated } from '@/shared/api/types';

export const inventoryKeys = {
  all: ['admin', 'inventory'] as const,
  list: (p: QueryParams) => ['admin', 'inventory', 'list', p] as const,
  movements: (variantId: number | 'all', p: QueryParams) => ['admin', 'inventory', 'movements', variantId, p] as const,
};

export function useInventory(params: QueryParams) {
  return useQuery({ queryKey: inventoryKeys.list(params), queryFn: () => api.get<Paginated<InventoryItem>>('/admin/inventory', params), placeholderData: keepPreviousData });
}

export function useMovements(variantId: number | null, params: QueryParams) {
  return useQuery({
    queryKey: inventoryKeys.movements(variantId ?? 'all', params),
    queryFn: () => api.get<Paginated<InventoryMovement>>(variantId ? `/admin/inventory/${variantId}/movements` : '/admin/inventory/movements', params),
    placeholderData: keepPreviousData,
  });
}

type MoveResult = Envelope<{ movement: InventoryMovement; inventory: InventoryItem }>;

function useInventoryMutation<V>(fn: (v: V) => Promise<unknown>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: inventoryKeys.all });
      void qc.invalidateQueries({ queryKey: ['admin', 'products'] });
      void qc.invalidateQueries({ queryKey: ['admin', 'dashboard'] });
    },
  });
}

export const useStockEntry = (variantId: number) =>
  useInventoryMutation((body: { quantity: number; reason: string }) => api.post<MoveResult>(`/admin/inventory/${variantId}/entries`, body));
export const useStockAdjust = (variantId: number) =>
  useInventoryMutation((body: { new_on_hand: number; reason: string; expected_on_hand: number }) => api.post<MoveResult>(`/admin/inventory/${variantId}/adjustments`, body));
export const useLowStockThreshold = (variantId: number) =>
  useInventoryMutation((body: { low_stock_threshold: number | null }) => api.patch<Envelope<InventoryItem>>(`/admin/inventory/${variantId}`, body));

export const exportMovementsCsv = (variantId: number, params: QueryParams) =>
  downloadFile(`/admin/inventory/${variantId}/movements`, { ...params, format: 'csv' }, `movimentos_${variantId}.csv`);
