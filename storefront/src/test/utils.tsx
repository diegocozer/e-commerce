import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { createMemoryRouter, RouterProvider, type RouteObject } from 'react-router';
import { AppProviders } from '@/app/providers';
import { createQueryClient } from '@/app/queryClient';

/** Renderiza com os providers reais do app e um router em memória. */
export function renderWithProviders(ui: ReactElement, { route = '/', path = '*', extraRoutes = [] }: { route?: string; path?: string; extraRoutes?: RouteObject[] } = {}) {
  const client = createQueryClient();
  client.setDefaultOptions({ queries: { ...client.getDefaultOptions().queries, retry: false } });
  const router = createMemoryRouter([{ path, element: ui }, ...extraRoutes], { initialEntries: [route] });
  const utils = render(
    <AppProviders client={client}>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return { ...utils, client, router };
}
