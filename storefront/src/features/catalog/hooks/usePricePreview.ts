import { useQuery } from '@tanstack/react-query';
import { toApiError } from '@/shared/api/errors';
import { useDebounce } from '@/shared/hooks/useDebounce';
import type { ValidConfiguration } from '@/shared/saleUnit/configuration';
import { catalogKeys, fetchPricePreview } from '../api';

/**
 * Prévia de preço no servidor (API §3.A): useQuery sem efeito colateral, debounce 300 ms,
 * staleTime 30 s. `matches` indica se a resposta corresponde à configuração atual — só então
 * o valor do servidor é exibido como verdade; senão mostramos a prévia local em "recalculando".
 */
export function usePricePreview(slug: string, variantId: number, valid: ValidConfiguration | null) {
  const current = valid ? JSON.stringify(valid.configuration) : null;
  const debounced = useDebounce(valid ? { body: valid.body, configuration: valid.configuration, key: current } : null, 300);
  const query = useQuery({
    queryKey: catalogKeys.pricePreview(slug, variantId, debounced?.configuration ?? { quantity: null, width_m: null, height_m: null, pieces: null }),
    queryFn: ({ signal }) => fetchPricePreview(slug, { variant_id: variantId, ...debounced!.body }, signal),
    enabled: Boolean(debounced),
    staleTime: 30_000,
    retry: (count, err) => {
      const e = toApiError(err);
      return e.status >= 400 && e.status < 500 ? false : count < 1;
    },
  });
  const matches = Boolean(debounced && current && debounced.key === current && query.data && !query.isFetching);
  const error = query.error && debounced?.key === current ? toApiError(query.error) : null;
  return {
    preview: matches ? query.data! : null,
    recalculating: Boolean(valid) && !matches && !error,
    error,
  };
}
