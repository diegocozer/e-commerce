import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import type { QueryParams } from '@/shared/api/client';

export interface ListParams {
  page: number;
  perPage: number;
  sort: string | undefined;
  q: string;
  filters: Record<string, string>;
}

const RESERVED = new Set(['page', 'per_page', 'sort', 'q']);

/**
 * Estado de listagem na URL (?q=&status=&page=&per_page=&sort=) — UX §5.4.
 * Qualquer mudança de filtro/busca/ordenação volta para a página 1.
 */
export function useListParams(defaults: { sort?: string; perPage?: number; filters?: Record<string, string> } = {}) {
  const [sp, setSp] = useSearchParams();
  const defaultsKey = JSON.stringify(defaults);

  const params: ListParams = useMemo(() => {
    const d = JSON.parse(defaultsKey) as typeof defaults;
    const filters: Record<string, string> = { ...(d.filters ?? {}) };
    sp.forEach((v, k) => {
      if (!RESERVED.has(k)) filters[k] = v;
    });
    for (const [k, v] of Object.entries(filters)) if (v === '') delete filters[k];
    return {
      page: Math.max(1, Number(sp.get('page') ?? 1) || 1),
      perPage: Number(sp.get('per_page') ?? d.perPage ?? 25) || 25,
      sort: sp.get('sort') ?? d.sort,
      q: sp.get('q') ?? '',
      filters,
    };
  }, [sp, defaultsKey]);

  const update = useCallback(
    (changes: Record<string, string | number | null | undefined>, resetPage = true) => {
      setSp(
        (prev) => {
          const next = new URLSearchParams(prev);
          for (const [k, v] of Object.entries(changes)) {
            if (v === null || v === undefined || v === '') next.delete(k);
            else next.set(k, String(v));
          }
          if (resetPage && !('page' in changes)) next.delete('page');
          return next;
        },
        { replace: true },
      );
    },
    [setSp],
  );

  const clear = useCallback(() => setSp(new URLSearchParams(), { replace: true }), [setSp]);

  /** Parâmetros prontos para a API (page, per_page, sort, q + filtros). */
  const apiParams: QueryParams = useMemo(
    () => ({
      page: params.page,
      per_page: params.perPage,
      sort: params.sort,
      q: params.q || undefined,
      ...params.filters,
    }),
    [params],
  );

  const activeFilterCount = Object.keys(params.filters).length + (params.q ? 1 : 0);

  return { params, apiParams, update, clear, activeFilterCount };
}
