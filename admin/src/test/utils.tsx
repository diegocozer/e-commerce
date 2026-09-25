import { render } from '@testing-library/react';
import { QueryClient } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { createMemoryRouter, RouterProvider, type RouteObject } from 'react-router-dom';
import { AppProviders } from '@/app/App';
import { routes } from '@/app/routes';
import { db } from '@/mocks/db';
import { makeMe } from '@/mocks/seed';
import type { PermissionName } from '@/shared/api/types';
import { meKey } from '@/shared/auth';

export function testQueryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity, refetchOnWindowFocus: false }, mutations: { retry: 0 } } });
}

/** Define a sessão do mock com permissões específicas (não super-admin). */
export function loginAs(permissions: PermissionName[] | 'super-admin') {
  db.loggedIn = true;
  db.me = permissions === 'super-admin' ? makeMe() : makeMe({ permissions, is_super_admin: false, user: { ...makeMe().user, roles: ['custom'] } });
  return db.me;
}

/** Renderiza a app completa (rotas reais) em memória, com sessão já hidratada. */
export function renderApp(path: string, opts: { client?: QueryClient; hydrate?: boolean } = {}) {
  const client = opts.client ?? testQueryClient();
  if (opts.hydrate !== false && db.loggedIn) client.setQueryData(meKey, db.me);
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const utils = render(
    <AppProviders client={client}>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return { ...utils, router, client };
}

/** Renderiza um elemento isolado com providers + roteador. */
export function renderWithProviders(ui: ReactElement, opts: { path?: string; client?: QueryClient; extraRoutes?: RouteObject[] } = {}) {
  const client = opts.client ?? testQueryClient();
  if (db.loggedIn) client.setQueryData(meKey, db.me);
  const router = createMemoryRouter([{ path: '*', element: ui }, ...(opts.extraRoutes ?? [])], { initialEntries: [opts.path ?? '/'] });
  const utils = render(
    <AppProviders client={client}>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return { ...utils, router, client };
}
