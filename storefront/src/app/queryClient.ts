import { QueryClient } from '@tanstack/react-query';
import { toApiError } from '@/shared/api/errors';

/** ARCHITECTURE §8.4: retry só para rede/5xx (máx. 2), nunca para 4xx; mutações sem retry. */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: (count, err) => {
          const e = toApiError(err);
          if (e.status >= 400 && e.status < 500) return false;
          return count < 2;
        },
        refetchOnWindowFocus: false,
        staleTime: 30_000,
      },
      mutations: { retry: 0 },
    },
  });
}
