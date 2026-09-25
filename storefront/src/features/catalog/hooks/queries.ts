import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { toApiError } from '@/shared/api/errors';
import {
  catalogKeys,
  fetchCategory,
  fetchCategoryTree,
  fetchPostalCode,
  fetchProduct,
  fetchProducts,
  fetchRelated,
  fetchSettings,
  normalizeFilters,
  type ProductFilters,
} from '../api';

const noRetryOn4xx = (count: number, err: unknown) => {
  const e = toApiError(err);
  return e.status >= 400 && e.status < 500 ? false : count < 2;
};

export const useSettings = () => useQuery({ queryKey: catalogKeys.settings, queryFn: fetchSettings, staleTime: 10 * 60_000 });
export const useCategoryTree = () => useQuery({ queryKey: catalogKeys.categoriesTree, queryFn: fetchCategoryTree, staleTime: 10 * 60_000 });
export const useCategory = (slug: string) =>
  useQuery({ queryKey: catalogKeys.category(slug), queryFn: () => fetchCategory(slug), staleTime: 5 * 60_000, retry: noRetryOn4xx });
export const useProduct = (slug: string) =>
  useQuery({ queryKey: catalogKeys.product(slug), queryFn: () => fetchProduct(slug), staleTime: 60_000, retry: noRetryOn4xx });
export const useRelated = (slug: string, enabled = true) =>
  useQuery({ queryKey: catalogKeys.related(slug), queryFn: () => fetchRelated(slug), staleTime: 5 * 60_000, enabled });

export function useProducts(filters: ProductFilters, enabled = true) {
  const f = normalizeFilters(filters);
  return useQuery({
    queryKey: catalogKeys.productList(f),
    queryFn: ({ signal }) => fetchProducts(f, signal),
    placeholderData: keepPreviousData,
    staleTime: 5 * 60_000,
    retry: noRetryOn4xx,
    enabled,
  });
}

export function usePostalCodeLookup(cep: string | null) {
  return useQuery({
    queryKey: catalogKeys.postalCode(cep ?? ''),
    queryFn: () => fetchPostalCode(cep!),
    enabled: Boolean(cep && cep.length === 8),
    staleTime: 24 * 60 * 60_000,
    retry: noRetryOn4xx,
  });
}
