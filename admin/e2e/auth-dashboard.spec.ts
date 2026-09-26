import { expect, test } from '@playwright/test';
import { ADMIN, login } from './support/helpers';

test.use({ storageState: { cookies: [], origins: [] } });

test('login com senha errada mostra erro e permanece no login', async ({ page }) => {
  await page.goto('/admin/entrar');
  await page.locator('input[name="email"]').fill(ADMIN.email);
  await page.locator('input[name="password"]').fill('senha-errada');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('alert')).toContainText('E-mail ou senha incorretos.');
  await expect(page).toHaveURL(/\/admin\/entrar/);
});

test('login e dashboard com KPIs', async ({ page }) => {
  await login(page);
  await expect(page).toHaveURL(/\/admin\/?$/);
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
  for (const kpi of ['Vendas hoje', 'Pedidos pagos no mês', 'Faturamento do mês', 'Ticket médio (mês)']) {
    await expect(page.getByText(kpi, { exact: true })).toBeVisible();
  }
  await expect(page.getByText(/Estoque baixo/).first()).toBeVisible();
  await expect(page.getByText('Administrador').first()).toBeVisible();
});
