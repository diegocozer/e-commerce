import { expect, test } from '@playwright/test';
import { logApiErrors, randomCpf } from './helpers';

test.beforeEach(async ({ page }) => {
  await page.goto('/entrar');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
});

test('login com senha errada → 422 e mensagem genérica; depois login da Maria', async ({ page }) => {
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'E-mail' }).fill('maria@example.com');
  await form.getByRole('textbox', { name: 'Senha' }).fill('senha-errada-123');
  const failed = page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/login'));
  await form.getByRole('button', { name: 'Entrar' }).click();
  const res = await failed;
  expect(res.status()).toBe(422);
  const body = (await res.json()) as { errors?: Record<string, string[]> };
  expect(body.errors?.email?.[0]).toBe('E-mail ou senha inválidos.');
  await expect(form.getByRole('alert')).toHaveText('E-mail ou senha inválidos.');
  await expect(page).toHaveURL(/\/entrar/);

  // Credenciais do seeder (CustomerSeeder: maria@example.com / password)
  await form.getByRole('textbox', { name: 'Senha' }).fill('password');
  await form.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).not.toHaveURL(/\/entrar/);
  await page.goto('/conta');
  await expect(page.getByText('Olá, Maria')).toBeVisible();
  await page.goto('/conta/enderecos');
  await expect(page.getByRole('main').getByText(/Rua das Palmeiras/).first()).toBeVisible();
});

test('cadastro com e-mail já usado → 422 mapeado no campo', async ({ page }) => {
  const apiErrors = logApiErrors(page, (url, status) => (status === 401 && url.endsWith('/api/v1/me')) || (status === 422 && url.endsWith('/auth/register')));
  await page.goto('/cadastro');
  await page.getByRole('button', { name: 'Pessoa física' }).click();
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'Nome completo' }).fill('Outra Maria');
  await form.getByRole('textbox', { name: 'CPF', exact: true }).fill(randomCpf());
  await form.getByRole('textbox', { name: 'Telefone/WhatsApp' }).fill('47999997777');
  await form.getByRole('textbox', { name: 'E-mail' }).fill('maria@example.com');
  await form.getByRole('textbox', { name: 'Senha', exact: true }).fill('SenhaForte#2026');
  await form.getByRole('textbox', { name: 'Confirmar senha' }).fill('SenhaForte#2026');
  await form.getByRole('checkbox', { name: /termos/i }).check();
  const res = page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/register'));
  await form.getByRole('button', { name: 'Criar conta' }).click();
  expect((await res).status()).toBe(422);
  const email = form.getByRole('textbox', { name: 'E-mail' });
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  await expect(page).toHaveURL(/\/cadastro/);
  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
});
