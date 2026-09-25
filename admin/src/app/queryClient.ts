import { MutationCache, QueryClient } from '@tanstack/react-query';
import { isApiError } from '@/shared/api/errors';

function shouldRetry(failureCount: number, error: unknown): boolean {
  if (isApiError(error) && error.status > 0 && error.status < 500) return false;
  return failureCount < 2;
}

/** Defaults do painel (ARCHITECTURE §8.4): listas 30 s, retry só p/ rede/5xx, refetch on focus. */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    mutationCache: new MutationCache(),
    defaultOptions: {
      queries: { staleTime: 30_000, retry: shouldRetry, refetchOnWindowFocus: true },
      mutations: { retry: 0 },
    },
  });
}
