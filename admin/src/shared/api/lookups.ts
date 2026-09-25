import { useQuery } from '@tanstack/react-query';
import { api, getData } from './client';
import type { AdminBrand, AdminCategory, AdminVariantPickerItem, Paginated, PriceList, ShippingMethod, ShippingZone } from './types';

/** Consultas de apoio usadas por várias features (selects/autocompletes). */
export function useCategoryTree(enabled = true) {
  return useQuery({ queryKey: ['admin', 'categories', 'tree'], queryFn: () => getData<AdminCategory[]>('/admin/categories'), staleTime: 5 * 60_000, enabled });
}

export interface CategoryOption {
  id: number;
  label: string;
  depth: number;
  is_active: boolean;
}
export function flattenCategories(list: AdminCategory[] | undefined, trail: string[] = []): CategoryOption[] {
  if (!list) return [];
  return list.flatMap((c) => [
    { id: c.id, label: [...trail, c.name].join(' › '), depth: c.depth, is_active: c.is_active },
    ...flattenCategories(c.children, [...trail, c.name]),
  ]);
}

export function useBrandOptions(enabled = true) {
  return useQuery({
    queryKey: ['admin', 'brands', 'list', { per_page: 100, sort: 'name' }],
    queryFn: () => api.get<Paginated<AdminBrand>>('/admin/brands', { per_page: 100, sort: 'name' }),
    staleTime: 5 * 60_000,
    enabled,
    select: (r) => r.data,
  });
}

export function usePriceLists(enabled = true) {
  return useQuery({ queryKey: ['admin', 'price-lists'], queryFn: () => getData<PriceList[]>('/admin/price-lists'), staleTime: 60_000, enabled });
}

export function useVariantPicker(q: string, enabled = true) {
  return useQuery({
    queryKey: ['admin', 'variants', 'picker', { q }],
    queryFn: () => api.get<Paginated<AdminVariantPickerItem>>('/admin/variants', { q: q || undefined, per_page: 20 }),
    enabled,
    select: (r) => r.data,
  });
}

export function useShippingMethods(enabled = true) {
  return useQuery({ queryKey: ['admin', 'shipping', 'methods'], queryFn: () => getData<ShippingMethod[]>('/admin/shipping/methods'), enabled });
}

export function useShippingZones(enabled = true) {
  return useQuery({ queryKey: ['admin', 'shipping', 'zones'], queryFn: () => getData<ShippingZone[]>('/admin/shipping/zones'), enabled });
}
