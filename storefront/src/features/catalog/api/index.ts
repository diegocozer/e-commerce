import { getData, http, postData } from '@/shared/api/client';
import type {
  AutocompleteResult,
  Brand,
  CategoryDetail,
  CategoryNode,
  LineConfiguration,
  PostalCodeInfo,
  PricePreview,
  ProductCard,
  ProductDetail,
  ProductListResponse,
  PublicSettings,
  SaleUnit,
  ShippingQuote,
} from '@/shared/api/types';
import type { LineBody } from '@/shared/saleUnit/configuration';

export interface ProductFilters {
  q?: string;
  category?: string;
  brand?: string;
  sale_unit?: SaleUnit;
  price_min_cents?: number;
  price_max_cents?: number;
  in_stock?: 1;
  featured?: 1;
  on_sale?: 1;
  sort?: string;
  page?: number;
  per_page?: number;
}

/** Remove chaves vazias para uma query key estável (API §7.1). */
export function normalizeFilters(f: ProductFilters): ProductFilters {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(f)) if (v !== undefined && v !== '' && v !== null) out[k] = v;
  return out as ProductFilters;
}

export const catalogKeys = {
  settings: ['settings', 'public'] as const,
  categoriesTree: ['categories', 'tree'] as const,
  category: (slug: string) => ['categories', 'detail', slug] as const,
  brands: ['brands'] as const,
  productList: (f: ProductFilters) => ['products', 'list', f] as const,
  autocomplete: (q: string) => ['products', 'autocomplete', q] as const,
  product: (slug: string) => ['products', 'detail', slug] as const,
  related: (slug: string) => ['products', 'related', slug] as const,
  pricePreview: (slug: string, variantId: number, configuration: LineConfiguration) => ['price-preview', slug, variantId, configuration] as const,
  shippingEstimate: (postalCode: string, items: unknown) => ['shipping-estimate', postalCode, items] as const,
  postalCode: (cep: string) => ['postal-codes', cep] as const,
  page: (slug: string) => ['pages', slug] as const,
};

export const fetchSettings = () => getData<PublicSettings>('/settings/public');
export const fetchCategoryTree = () => getData<CategoryNode[]>('/categories');
export const fetchCategory = (slug: string) => getData<CategoryDetail>(`/categories/${encodeURIComponent(slug)}`);
export const fetchBrands = () => getData<Brand[]>('/brands');

export async function fetchProducts(filters: ProductFilters, signal?: AbortSignal): Promise<ProductListResponse> {
  const res = await http.get<ProductListResponse>('/products', { params: normalizeFilters(filters), signal });
  return res.data;
}

export const fetchAutocomplete = (q: string, signal?: AbortSignal) => getData<AutocompleteResult>('/products/autocomplete', { params: { q }, signal });
export const fetchProduct = (slug: string) => getData<ProductDetail>(`/products/${encodeURIComponent(slug)}`);
export const fetchRelated = (slug: string) => getData<ProductCard[]>(`/products/${encodeURIComponent(slug)}/related`);

export function fetchPricePreview(slug: string, body: { variant_id: number } & LineBody, signal?: AbortSignal): Promise<PricePreview> {
  return postData<PricePreview>(`/products/${encodeURIComponent(slug)}/price-preview`, body, { signal });
}

export function fetchShippingEstimate(postalCode: string, items: ({ variant_id: number } & LineBody)[]): Promise<ShippingQuote> {
  return postData<ShippingQuote>('/shipping/quote', { postal_code: postalCode, items });
}

export const fetchPostalCode = (cep: string) => getData<PostalCodeInfo>(`/postal-codes/${cep}`);
