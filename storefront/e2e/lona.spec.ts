import { expect, test } from '@playwright/test';
import { brl, logApiErrors, searchAndOpenProduct } from './helpers';

// Lona vendida por m² (BUSINESS_RULES / API.md §4.3): 1,20 × 2,50 → 3,00 m² → R$ 90,00.
test('lona m²: 1,20 × 2,50 → Área 3,00 m² / R$ 90,00 e adiciona ao carrinho', async ({ page }) => {
  const apiErrors = logApiErrors(page, (url, status) => status === 401 && url.endsWith('/api/v1/me'));
  await page.goto('/');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
  await searchAndOpenProduct(page, 'Lona Frontlight', 'Lona Frontlight 440g');

  const config = page.getByRole('region', { name: 'Configurar quantidade' });
  const width = config.getByRole('textbox', { name: 'Largura (m)' }).or(config.getByRole('spinbutton', { name: 'Largura (m)' }));
  const height = config.getByRole('textbox', { name: 'Altura (m)' }).or(config.getByRole('spinbutton', { name: 'Altura (m)' }));
  await width.fill('1,20');
  await height.fill('2,50');
  await expect(config.getByText(/Área:\s*3,00\s*m²/)).toBeVisible();
  await expect(config.getByLabel(/^Total:/)).toHaveText(brl('90,00'));

  // Espera a prévia do servidor (POST price-preview) confirmar o valor antes de adicionar.
  await expect(config.locator('[aria-busy="false"]').first()).toBeVisible();
  const added = page.waitForResponse((r) => r.url().endsWith('/api/v1/cart/items') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Adicionar ao carrinho' }).click();
  const addRes = await added;
  expect(addRes.status(), await addRes.text()).toBe(201);

  await page.goto('/carrinho');
  const item = page.getByRole('article', { name: 'Lona Frontlight 440g' });
  await expect(item).toBeVisible();
  await expect(item.getByText(brl('90,00')).first()).toBeVisible();
  await expect(item.getByText(/1,20\s*m\s*×\s*2,50\s*m/).first()).toBeVisible();
  await expect(page.getByRole('main').getByText(/3,00\s*m²/).first()).toBeVisible();

  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
});
