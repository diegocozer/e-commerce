import { useSearchParams } from 'react-router';
import type { SaleUnit } from '@/shared/api/types';
import { storageGet, storageSet } from '@/shared/lib/storage';
import type { ProductFilters } from '../api';

// Estado da listagem na URL (UX §3.1): sub, marca, categoria, preco (reais "0-100"),
// unidade, estoque, ordem, pagina, vista.
export const SORT_OPTIONS = [
  { value: 'relevancia', api: 'relevance', label: 'Relevância' },
  { value: 'mais_vendidos', api: 'best_selling', label: 'Mais vendidos' },
  { value: 'preco_asc', api: 'price', label: 'Menor preço' },
  { value: 'preco_desc', api: '-price', label: 'Maior preço' },
  { value: 'nome', api: 'name', label: 'Nome A–Z' },
  { value: 'lancamentos', api: '-created_at', label: 'Lançamentos' },
] as const;

const SALE_UNITS: SaleUnit[] = ['UNIT', 'LINEAR_METER', 'SQUARE_METER', 'ROLL', 'KG', 'BOX'];
const VIEW_KEY = 'cv_listing_view';

export interface ListingState {
  sub: string[];
  brands: string[];
  categories: string[];
  price: [number, number] | null;
  unit: SaleUnit | null;
  inStock: boolean;
  sort: string;
  page: number;
  view: 'grid' | 'list';
}

const list = (v: string | null) => (v ? v.split(',').filter(Boolean) : []);

export function useListingParams(mode: 'category' | 'search') {
  const [params, setParams] = useSearchParams();
  const priceRaw = params.get('preco');
  const priceMatch = priceRaw?.match(/^(\d+)-(\d+)$/);
  const unitRaw = params.get('unidade') as SaleUnit | null;
  const viewRaw = params.get('vista') ?? storageGet(VIEW_KEY);
  const defaultSort = mode === 'search' ? 'relevancia' : 'mais_vendidos';
  const sortRaw = params.get('ordem');
  const state: ListingState = {
    sub: list(params.get('sub')),
    brands: list(params.get('marca')),
    categories: list(params.get('categoria')),
    price: priceMatch ? [Number(priceMatch[1]), Number(priceMatch[2])] : null,
    unit: unitRaw && SALE_UNITS.includes(unitRaw) ? unitRaw : null,
    inStock: params.get('estoque') === '1',
    sort: SORT_OPTIONS.some((s) => s.value === sortRaw) ? sortRaw! : defaultSort,
    page: Math.max(1, Number(params.get('pagina')) || 1),
    view: viewRaw === 'lista' || viewRaw === 'list' ? 'list' : 'grid',
  };

  const update = (patch: Partial<Record<'sub' | 'marca' | 'categoria' | 'preco' | 'unidade' | 'estoque' | 'ordem' | 'pagina' | 'vista', string | null>>, keepPage = false) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '') next.delete(k);
      else next.set(k, v);
    }
    if (!keepPage && !('pagina' in patch)) next.delete('pagina');
    if (patch.vista) storageSet(VIEW_KEY, patch.vista);
    setParams(next, { replace: false });
  };

  const clear = () => {
    const next = new URLSearchParams();
    const q = params.get('q');
    if (q) next.set('q', q);
    setParams(next);
  };

  return { state, update, clear, q: params.get('q') ?? '' };
}

export function toApiFilters(state: ListingState, base: { category?: string; q?: string }): ProductFilters {
  const sort = SORT_OPTIONS.find((s) => s.value === state.sort)?.api;
  const category = state.sub.length ? state.sub.join(',') : state.categories.length ? state.categories.join(',') : base.category;
  return {
    q: base.q || undefined,
    category,
    brand: state.brands.length ? state.brands.join(',') : undefined,
    sale_unit: state.unit ?? undefined,
    price_min_cents: state.price ? state.price[0] * 100 : undefined,
    price_max_cents: state.price ? state.price[1] * 100 : undefined,
    in_stock: state.inStock ? 1 : undefined,
    sort,
    page: state.page,
    per_page: 24,
  };
}
