import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { loginAs, renderApp, testQueryClient } from '@/test/utils';

describe('UI sensível a permissões', () => {
  it('esconde itens de menu e ações sem permissão', async () => {
    loginAs(['dashboard.view', 'products.view', 'orders.view']);
    renderApp('/produtos');
    expect(await screen.findByRole('heading', { name: /Produtos/, level: 1 })).toBeInTheDocument();
    const nav = screen.getAllByRole('navigation', { name: 'Menu principal' })[0];
    expect(nav).toHaveTextContent('Produtos');
    expect(nav).toHaveTextContent('Pedidos');
    expect(nav).not.toHaveTextContent('Estoque');
    expect(nav).not.toHaveTextContent('Usuários');
    expect(nav).not.toHaveTextContent('Frete');
    // Sem products.manage: botão não é renderizado (não só desabilitado).
    expect(screen.queryByRole('link', { name: 'Novo produto' })).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: 'Selecionar todos da página' })).not.toBeInTheDocument();
  });

  it('mostra as ações com a permissão', async () => {
    loginAs(['dashboard.view', 'products.view', 'products.manage']);
    renderApp('/produtos');
    expect(await screen.findByRole('link', { name: 'Novo produto' })).toBeInTheDocument();
  });

  it('ação de estoque some sem inventory.adjust', async () => {
    loginAs(['inventory.view', 'inventory.move']);
    renderApp('/estoque');
    expect((await screen.findAllByRole('button', { name: 'Entrada' })).length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Ajustar' })).not.toBeInTheDocument();
  });

  it('rota sem permissão mostra 403', async () => {
    loginAs(['dashboard.view']);
    renderApp('/usuarios');
    expect(await screen.findByText('403 — Acesso negado')).toBeInTheDocument();
    expect(screen.getByText(/Você não tem acesso a esta área/)).toBeInTheDocument();
  });

  it('sem sessão redireciona ao login com return URL', async () => {
    db.loggedIn = false;
    const { router } = renderApp('/pedidos?status=paid', { hydrate: false });
    await waitFor(() => expect(router.state.location.pathname).toBe('/entrar'));
    expect(router.state.location.search).toBe(`?redirect=${encodeURIComponent('/pedidos?status=paid')}`);
    expect(await screen.findByRole('heading', { name: 'Entrar no painel' })).toBeInTheDocument();
  });

  it('401 (sessão expirada) em uso redireciona ao login preservando a rota', async () => {
    loginAs('super-admin');
    server.use(http.get('*/api/v1/admin/brands', () => HttpResponse.json({ message: 'Sessão expirada', code: 'admin_session_expired' }, { status: 401 })));
    const client = testQueryClient();
    const { router } = renderApp('/marcas', { client });
    await waitFor(() => expect(router.state.location.pathname).toBe('/entrar'));
    expect(router.state.location.search).toContain('expirada=1');
    expect(await screen.findByText(/Sua sessão expirou/)).toBeInTheDocument();
  });
});
