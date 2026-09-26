import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { renderApp } from '@/test/utils';
import { safeRedirect } from '../pages/LoginPage';

async function fill(email: string, password: string) {
  await userEvent.type(await screen.findByLabelText(/E-mail/), email);
  await userEvent.type(screen.getByLabelText(/Senha/), password);
  await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));
}

describe('Login do painel', () => {
  it('422 de credenciais mostra mensagem no topo sem logar', async () => {
    db.loggedIn = false;
    renderApp('/entrar', { hydrate: false });
    await fill('admin@comunika.test', 'errada');
    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha incorretos.');
  });

  it('422 com erro de campo vai para o campo', async () => {
    db.loggedIn = false;
    server.use(http.post('*/api/v1/admin/auth/login', () => HttpResponse.json({ message: 'x', errors: { password: ['A senha deve ter ao menos 12 caracteres.'] } }, { status: 422 })));
    renderApp('/entrar', { hydrate: false });
    await fill('admin@comunika.test', 'curta');
    expect(await screen.findByText('A senha deve ter ao menos 12 caracteres.')).toBeInTheDocument();
    expect(screen.getByLabelText(/Senha/)).toHaveAttribute('aria-invalid', 'true');
  });

  it('valida no cliente antes de enviar', async () => {
    db.loggedIn = false;
    renderApp('/entrar', { hydrate: false });
    await userEvent.click(await screen.findByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Informe seu e-mail.')).toBeInTheDocument();
    expect(screen.getByText('Informe sua senha.')).toBeInTheDocument();
  });

  it('403 account_disabled e 429', async () => {
    db.loggedIn = false;
    renderApp('/entrar', { hydrate: false });
    await fill('bloqueado@comunika.test', 'qualquer');
    expect(await screen.findByRole('alert')).toHaveTextContent('Acesso desativado. Procure o administrador.');
  });

  it('login bem-sucedido vai para o redirect interno', async () => {
    db.loggedIn = false;
    const { router } = renderApp(`/entrar?redirect=${encodeURIComponent('/pedidos')}`, { hydrate: false });
    await fill('admin@comunika.test', 'SenhaForte!2026');
    await waitFor(() => expect(router.state.location.pathname).toBe('/pedidos'));
  });

  it('redirect só aceita caminhos internos', () => {
    expect(safeRedirect('//evil.com')).toBe('/');
    expect(safeRedirect('https://evil.com')).toBe('/');
    expect(safeRedirect('/pedidos/1')).toBe('/pedidos/1');
  });
});
