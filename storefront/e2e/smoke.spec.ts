import { expect, test, type Page } from '@playwright/test';
import { logApiErrors } from './helpers';

// Varre as páginas principais contra o backend real: nenhuma resposta /api com erro
// inesperado e nenhum erro de JS (drift de formato costuma virar exceção de render).
function watch(page: Page) {
  const pageErrors: string[] = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  const apiErrors = logApiErrors(page, (url, status) => status === 401 && url.endsWith('/api/v1/me'));
  return { pageErrors, apiErrors };
}

test('visitante: home, categoria, produto com estimativa de frete, institucional', async ({ page }) => {
  const { pageErrors, apiErrors } = watch(page);
  await page.goto('/');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
  await expect(page.getByRole('main')).toBeVisible();
  await page.getByRole('navigation', { name: 'Categorias principais' }).getByRole('link', { name: 'Lonas' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Lonas' })).toBeVisible();
  await page.getByRole('link', { name: 'Lona Frontlight 440g' }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'Lona Frontlight 440g' })).toBeVisible();
  await page.goto('/vinis/vinil-adesivo-branco-122m');
  await expect(page.getByRole('heading', { level: 1, name: 'Vinil Adesivo Branco' })).toBeVisible();
  const estimator = page.getByRole('main');
  await estimator.getByRole('textbox', { name: 'CEP' }).first().fill('89010-000');
  await estimator.getByRole('button', { name: 'Calcular', exact: true }).first().click();
  await expect(estimator.getByText(/Entrega própria/).first()).toBeVisible();
  await page.goto('/busca?q=VIN-BR');
  await expect(page.getByRole('link', { name: 'Vinil Adesivo Branco' }).first()).toBeVisible();
  await page.goto('/institucional/termos');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await page.goto('/verificar-email');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
});

test('Maria: área do cliente (visão geral, pedidos, endereços, dados, senha)', async ({ page }) => {
  const { pageErrors, apiErrors } = watch(page);
  await page.goto('/entrar');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'E-mail' }).fill('maria@example.com');
  await form.getByRole('textbox', { name: 'Senha' }).fill('password');
  await form.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).not.toHaveURL(/\/entrar/);
  for (const [path, heading] of [
    ['/conta', /Olá|Minha conta|Visão geral/],
    ['/conta/pedidos', /Pedidos/],
    ['/conta/enderecos', /Endereços/],
    ['/conta/dados', /Dados cadastrais/],
    ['/conta/senha', /senha/i],
  ] as const) {
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1 }).filter({ hasText: heading }).first()).toBeVisible();
  }
  await page.goto('/conta/dados');
  await expect(page.getByRole('main').getByText('Baixar meus dados')).toHaveCount(0);
  await page.goto('/conta/enderecos');
  await expect(page.getByRole('main').getByText(/Rua das Palmeiras/).first()).toBeVisible();
  expect(pageErrors, pageErrors.join('\n')).toEqual([]);
  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
});
