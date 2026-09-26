import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope, type QueryParams } from '@/shared/api/client';
import type { CarrierDriver, RuleSaveResponse, SimulationRequest, SimulationResult, ZoneSaveResponse, ZoneTestResult } from '@/shared/api/extraTypes';
import type { Carrier, IbgeCity, ShippingMethod, ShippingRule, ShippingZone } from '@/shared/api/types';

export { useShippingMethods, useShippingZones } from '@/shared/api/lookups';

const k = (...rest: unknown[]) => ['admin', 'shipping', ...rest] as const;

export function useCarriers() {
  return useQuery({ queryKey: k('carriers'), queryFn: () => getData<Carrier[]>('/admin/shipping/carriers') });
}
export function useCarrierDrivers() {
  return useQuery({ queryKey: k('carrier-drivers'), queryFn: () => getData<CarrierDriver[]>('/admin/shipping/carriers/drivers'), staleTime: 10 * 60_000 });
}
export function useZone(id: number | null) {
  return useQuery({ queryKey: k('zones', id), queryFn: () => getData<ShippingZone>(`/admin/shipping/zones/${id}`), enabled: id !== null });
}
export function useRules(params: QueryParams) {
  return useQuery({ queryKey: k('rules', params), queryFn: () => getData<ShippingRule[]>('/admin/shipping/rules', params) });
}
export function useCitySearch(search: string, state?: string) {
  return useQuery({ queryKey: k('cities', search, state), queryFn: () => getData<IbgeCity[]>('/admin/shipping/cities', { search, state }), enabled: search.length >= 2 });
}

function useShippingMutation<V, R>(fn: (v: V) => Promise<R>) {
  const qc = useQueryClient();
  return useMutation({ mutationFn: fn, onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'shipping'] }) });
}

export const useSaveCarrier = (id: number | null) =>
  useShippingMutation((body: Record<string, unknown>) => (id === null ? api.post<Envelope<Carrier>>('/admin/shipping/carriers', body) : api.patch<Envelope<Carrier>>(`/admin/shipping/carriers/${id}`, body)));
export const testCarrier = (id: number) => api.post<Envelope<{ ok: boolean; duration_ms: number; message: string | null }>>(`/admin/shipping/carriers/${id}/test`).then((r) => r.data);
export const deleteCarrier = (id: number) => api.delete(`/admin/shipping/carriers/${id}`);

export const useSaveMethod = (id: number | null) =>
  useShippingMutation((body: Record<string, unknown>) => (id === null ? api.post<Envelope<ShippingMethod>>('/admin/shipping/methods', body) : api.patch<Envelope<ShippingMethod>>(`/admin/shipping/methods/${id}`, body)));
export const useReorderMethods = () => useShippingMutation((ids: number[]) => api.put<void>('/admin/shipping/methods/reorder', { ids }));
export const deleteMethod = (id: number) => api.delete(`/admin/shipping/methods/${id}`);

export const useSaveZone = (id: number | null) =>
  useShippingMutation((body: Record<string, unknown>) => (id === null ? api.post<ZoneSaveResponse>('/admin/shipping/zones', body) : api.patch<ZoneSaveResponse>(`/admin/shipping/zones/${id}`, body)));
export const deleteZone = (id: number) => api.delete(`/admin/shipping/zones/${id}`);
export const testZone = (id: number, postal_code: string) => api.post<Envelope<ZoneTestResult>>(`/admin/shipping/zones/${id}/test`, { postal_code }).then((r) => r.data);

export const useSaveRule = (id: number | null) =>
  useShippingMutation((body: Record<string, unknown>) => (id === null ? api.post<RuleSaveResponse>('/admin/shipping/rules', body) : api.patch<RuleSaveResponse>(`/admin/shipping/rules/${id}`, body)));
export const usePatchRule = () => useShippingMutation(({ id, body }: { id: number; body: Record<string, unknown> }) => api.patch<RuleSaveResponse>(`/admin/shipping/rules/${id}`, body));
export const useReorderRules = () => useShippingMutation((body: { method_id: number; zone_id: number | null; ids: number[] }) => api.post<Envelope<ShippingRule[]>>('/admin/shipping/rules/reorder', body));
export const useDuplicateRule = () => useShippingMutation((id: number) => api.post<Envelope<ShippingRule>>(`/admin/shipping/rules/${id}/duplicate`));
export const deleteRule = (id: number) => api.delete(`/admin/shipping/rules/${id}`);

export function useSimulate() {
  return useMutation({ mutationFn: (body: SimulationRequest) => api.post<Envelope<SimulationResult>>('/admin/shipping/simulate', body).then((r) => r.data) });
}
