import { render, screen } from '@testing-library/react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { describe, expect, it } from 'vitest';
import { AppProviders } from './providers';
import { createQueryClient } from './queryClient';
import { routes } from './router';

function renderAt(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <AppProviders client={createQueryClient()}>
      <RouterProvider router={router} />
    </AppProviders>,
  );
  return router;
}

describe('rotas (ADR-015/026a)', () => {
  it('rota estática reservada vence a de categoria: /carrinho', async () => {
    renderAt('/carrinho');
    expect(await screen.findByRole('heading', { level: 1, name: 'Carrinho' })).toBeInTheDocument();
    expect(await screen.findByText('Seu carrinho está vazio')).toBeInTheDocument();
  });

  it('/:categorySlug → listagem da categoria', async () => {
    renderAt('/vinis');
    expect(await screen.findByRole('heading', { level: 1, name: 'Vinis' })).toBeInTheDocument();
    expect(await screen.findByText('Vinil Adesivo Branco')).toBeInTheDocument();
  });

  it('slug reservado sem página (/admin) não vira categoria → 404', async () => {
    renderAt('/admin');
    expect(await screen.findByRole('heading', { name: 'Página não encontrada' })).toBeInTheDocument();
  });

  it('/conta exige login → /entrar?redirect=', async () => {
    const router = renderAt('/conta/pedidos');
    expect(await screen.findByRole('heading', { level: 1, name: 'Entrar' })).toBeInTheDocument();
    expect(router.state.location.search).toBe('?redirect=%2Fconta%2Fpedidos');
  });
});
