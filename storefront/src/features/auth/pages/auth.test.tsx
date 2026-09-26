import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import { renderWithProviders } from '@/test/utils';
import LoginPage from './LoginPage';
import RegisterPage from './RegisterPage';

describe('Login', () => {
  it('422: mostra "E-mail ou senha inválidos." sem indicar o campo', async () => {
    const user = userEvent.setup();
    renderWithProviders(<LoginPage />, { route: '/entrar', path: '/entrar' });
    await user.type(screen.getByLabelText(/E-mail/), 'maria@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'errada123');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha inválidos.');
  });

  it('422 com erros por campo → setError no RHF', async () => {
    server.use(
      http.post('*/api/v1/auth/login', () =>
        HttpResponse.json({ message: 'O campo senha é obrigatório.', errors: { password: ['A senha deve ter no máximo 72 caracteres.'] } }, { status: 422 }),
      ),
    );
    const user = userEvent.setup();
    renderWithProviders(<LoginPage />, { route: '/entrar', path: '/entrar' });
    await user.type(screen.getByLabelText(/E-mail/), 'maria@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'qualquer1');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('A senha deve ter no máximo 72 caracteres.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Senha/)).toHaveAttribute('aria-invalid', 'true');
  });

  it('validação local antes de enviar', async () => {
    const user = userEvent.setup();
    renderWithProviders(<LoginPage />, { route: '/entrar', path: '/entrar' });
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    expect(await screen.findByText('Informe seu e-mail')).toBeInTheDocument();
    expect(screen.getByText('Informe sua senha')).toBeInTheDocument();
  });

  it('sucesso: redireciona apenas para caminho interno', async () => {
    const user = userEvent.setup();
    const { router } = renderWithProviders(<LoginPage />, {
      route: '/entrar?redirect=https://evil.example.com',
      path: '/entrar',
      extraRoutes: [{ path: '/conta', element: <p>Conta</p> }],
    });
    await user.type(screen.getByLabelText(/E-mail/), 'maria@example.com');
    await user.type(screen.getByLabelText(/^Senha/), 'senha1234');
    await user.click(screen.getByRole('button', { name: 'Entrar' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/conta'));
  });
});

describe('Cadastro', () => {
  it('PJ: valida CNPJ, IE ou isento e aplica 422 em company.cnpj', async () => {
    server.use(
      http.post('*/api/v1/auth/register', () =>
        HttpResponse.json({ message: 'Já existe uma conta com este CNPJ.', errors: { 'company.cnpj': ['Já existe uma conta com este CNPJ.'] } }, { status: 422 }),
      ),
    );
    const user = userEvent.setup();
    renderWithProviders(<RegisterPage />, { route: '/cadastro', path: '/cadastro' });
    await user.type(screen.getByLabelText(/^CNPJ/), '11222333000180');
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));
    expect(await screen.findByText('CNPJ inválido')).toBeInTheDocument();
    expect(screen.getByText('Informe a inscrição estadual ou marque Isento')).toBeInTheDocument();

    await user.clear(screen.getByLabelText(/^CNPJ/));
    await user.type(screen.getByLabelText(/^CNPJ/), '12ABC34501DE35');
    expect(screen.getByLabelText(/^CNPJ/)).toHaveValue('12.ABC.345/01DE-35');
    await user.type(screen.getByLabelText(/Razão social/), 'Gráfica Azul Ltda');
    await user.click(screen.getByRole('checkbox', { name: 'Isento' }));
    await user.type(screen.getByLabelText(/Nome completo/), 'Ana Souza');
    await user.type(screen.getByLabelText(/Telefone/), '47999990000');
    await user.type(screen.getByLabelText(/^E-mail/), 'ana@grafica.com');
    await user.type(screen.getByLabelText(/^Senha/), 'segura123');
    await user.type(screen.getByLabelText(/Confirmar senha/), 'segura123');
    await user.click(screen.getByRole('checkbox', { name: /Termos de uso/ }));
    await user.click(screen.getByRole('button', { name: 'Criar conta' }));
    expect(await screen.findAllByText(/Já existe uma conta com este CNPJ\./)).not.toHaveLength(0);
    expect(screen.getByLabelText(/^CNPJ/)).toHaveAttribute('aria-invalid', 'true');
  });
});
