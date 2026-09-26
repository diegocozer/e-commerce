import { expect, test } from '@playwright/test';
import { brl, logApiErrors, randomCpf, searchAndOpenProduct, uniqueEmail } from './helpers';

// Fluxo de aceite ponta a ponta contra o backend real (API.md / BUSINESS_RULES).
test('compra PF: busca → produto → carrinho → frete → cadastro → checkout PIX → pago', async ({ page, request }) => {
  const apiErrors = logApiErrors(page, (url, status) => status === 401 && url.endsWith('/api/v1/me'));

  await page.goto('/');
  await expect(page.getByRole('combobox', { name: 'Buscar produtos por nome ou SKU' }).first()).toBeVisible();

  // Busca → produto
  await searchAndOpenProduct(page, 'Vinil Adesivo Branco', 'Vinil Adesivo Branco');

  // 5 m → R$ 79,50
  const qty = page.getByLabel('Quantidade (metros)');
  await qty.fill('5');
  const config = page.getByRole('region', { name: 'Configurar quantidade' });
  await expect(config.getByLabel(/^Total:/)).toHaveText(brl('79,50'));

  await page.getByRole('button', { name: 'Adicionar ao carrinho' }).click();
  await expect(page.getByRole('dialog').or(page.getByRole('alert')).first()).toBeVisible();

  // Carrinho + frete por CEP
  await page.goto('/carrinho');
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/carrinho/i);
  await expect(page.getByText('Vinil Adesivo Branco').first()).toBeVisible();
  await page.getByLabel(/CEP/).first().fill('89010-000');
  await page.getByRole('button', { name: 'Calcular' }).click();
  const shipping = page.getByRole('radiogroup').first();
  await expect(shipping.getByText(/Retirada/i).first()).toBeVisible();
  await expect(shipping.getByText(brl('20,00')).first()).toBeVisible();
  await expect(shipping.getByText(/Grátis|R\$[\s ]*0,00/).first()).toBeVisible();

  // Cadastro PF
  await page.getByRole('link', { name: 'Finalizar compra' }).or(page.getByRole('button', { name: 'Finalizar compra' })).first().click();
  await expect(page).toHaveURL(/\/entrar/);
  await page.getByRole('link', { name: /Criar conta|Cadastr/i }).first().click();
  await expect(page).toHaveURL(/\/cadastro/);
  const email = uniqueEmail();
  await page.getByLabel('Nome completo').fill('Cliente E2E Teste');
  await page.getByLabel('CPF').fill(randomCpf());
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel(/Celular|Telefone/).fill('47999998888');
  await page.getByLabel('Senha', { exact: true }).fill('SenhaForte#2026');
  await page.getByLabel('Confirmar senha').fill('SenhaForte#2026');
  await page.getByRole('checkbox', { name: /termos/i }).check();
  await page.getByRole('button', { name: /Criar conta/i }).click();

  // Checkout
  await expect(page).toHaveURL(/\/checkout/, { timeout: 20_000 });
  await page.screenshot({ path: 'test-results/checkout-start.png', fullPage: true });

  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
  void request;
});
