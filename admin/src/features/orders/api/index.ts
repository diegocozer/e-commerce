import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { OrderStatusCounts } from '@/shared/api/extraTypes';
import type { AdminOrder, AdminOrderListItem, OrderStatus, Paginated } from '@/shared/api/types';

export const orderKeys = {
  all: ['admin', 'orders'] as const,
  list: (p: QueryParams) => ['admin', 'orders', 'list', p] as const,
  counts: (p: QueryParams) => ['admin', 'orders', 'status-counts', p] as const,
  detail: (id: number) => ['admin', 'orders', 'detail', id] as const,
};

export function useOrders(params: QueryParams) {
  return useQuery({
    queryKey: orderKeys.list(params),
    queryFn: () => api.get<Paginated<AdminOrderListItem>>('/admin/orders', params),
    placeholderData: keepPreviousData,
  });
}

export function useOrderStatusCounts(params: QueryParams, opts: { enabled?: boolean; refetchInterval?: number } = {}) {
  return useQuery({
    queryKey: orderKeys.counts(params),
    queryFn: () => getData<OrderStatusCounts>('/admin/orders/status-counts', params),
    enabled: opts.enabled ?? true,
    refetchInterval: opts.refetchInterval,
  });
}

export function useOrder(id: number) {
  return useQuery({ queryKey: orderKeys.detail(id), queryFn: () => getData<AdminOrder>(`/admin/orders/${id}`) });
}

export interface TransitionInput {
  to_status: OrderStatus;
  note?: string | null;
  tracking_code?: string | null;
  tracking_url?: string | null;
  carrier_name?: string | null;
  picked_up_by_name?: string | null;
  picked_up_by_document?: string | null;
}
export interface CancelInput {
  reason: string;
  confirm_refund?: boolean;
}
export interface OrderPatch {
  internal_notes?: string | null;
  tracking_code?: string | null;
  tracking_url?: string | null;
}

/** Mutações de pedido: setQueryData do detalhe + invalida lista, contadores e dashboard (API §7.2). Nunca otimista. */
function useOrderMutation<V>(id: number, fn: (v: V) => Promise<Envelope<AdminOrder>>) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (res) => {
      qc.setQueryData(orderKeys.detail(id), res.data);
      void qc.invalidateQueries({ queryKey: ['admin', 'orders', 'list'] });
      void qc.invalidateQueries({ queryKey: ['admin', 'orders', 'status-counts'] });
      void qc.invalidateQueries({ queryKey: ['admin', 'dashboard'] });
    },
  });
}

export const useTransitionOrder = (id: number) =>
  useOrderMutation(id, (body: TransitionInput) => api.post<Envelope<AdminOrder>>(`/admin/orders/${id}/transitions`, body));
export const useCancelOrder = (id: number) =>
  useOrderMutation(id, (body: CancelInput) => api.post<Envelope<AdminOrder>>(`/admin/orders/${id}/cancel`, body));
export const usePatchOrder = (id: number) =>
  useOrderMutation(id, (body: OrderPatch) => api.patch<Envelope<AdminOrder>>(`/admin/orders/${id}`, body));
export const useReconcilePayment = (id: number) =>
  useOrderMutation(id, () => api.post<Envelope<AdminOrder>>(`/admin/orders/${id}/payments/reconcile`));
export const useDismissCancellation = (id: number) =>
  useOrderMutation(id, (note: string) => api.post<Envelope<AdminOrder>>(`/admin/orders/${id}/cancellation-request/dismiss`, { note }));

export function useRevealOrderDocument(id: number) {
  return useMutation({
    mutationFn: () => api.post<Envelope<{ customer_document: string; picked_up_by_document: string | null }>>(`/admin/orders/${id}/reveal-document`).then((r) => r.data),
  });
}
