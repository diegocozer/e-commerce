import { expect, test } from '@playwright/test';
import { selectOption, uniq } from './support/helpers';

test('produto LINEAR_METER: criar com variante e estoque inicial, editar preço e ver na lista', async ({ page }) => {
  const tag = uniq();
  const name = `Vinil E2E ${tag}`;
  const sku = `E2E-${tag}`;

  await page.goto('/admin/produtos');
  await expect(page.getByRole('heading', { name: /Produtos/ })).toBeVisible();
  await page.getByRole('link', { name: 'Novo produto' }).or(page.getByRole('button', { name: 'Novo produto' })).first().click();
  await expect(page).toHaveURL(/\/admin\/produtos\/novo$/);

  await page.locator('input[name="name"]').fill(name);
  await selectOption(page, /Categoria principal/, /Vinil Adesivo/);
  await selectOption(page, /Unidade de venda/, /metro linear/i);
  await expect(page.getByTestId('linear-fields')).toBeVisible();
  await page.locator('input[name="min_quantity"]').fill('1');
  await page.locator('input[name="quantity_step"]').fill('0,5');
  await page.locator('input[name="fixed_width_m"]').fill('1,22');

  await page.locator('input[name="variants.0.sku"]').fill(sku);
  await page.locator('input[name="variants.0.name"]').fill('Branco');
  await page.locator('input[name="variants.0.price_cents"]').fill('12,90');
  await page.locator('input[name="variants.0.weight_grams"]').fill('250');
  await page.locator('input[name="variants.0.initial_stock"]').fill('50');

  await page.getByRole('button', { name: 'Salvar produto' }).click();
  await expect(page).toHaveURL(/\/admin\/produtos\/\d+$/);
  await expect(page.getByText(`Editar produto: ${name}`)).toBeVisible();
  await expect(page.locator(`a[href="/admin/estoque?q=${sku}"]`)).toHaveText(/^50(,000)?\s*m/);

  // Editar preço
  await page.locator('input[name="variants.0.price_cents"]').fill('13,50');
  await page.getByRole('button', { name: 'Salvar produto' }).click();
  await expect(page.getByText(/Produto salvo/i).first()).toBeVisible();

  await page.goto(`/admin/produtos?q=${encodeURIComponent(tag)}`);
  const row = page.getByRole('row', { name: new RegExp(name) });
  await expect(row).toBeVisible();
  await expect(row).toContainText(sku);
  await expect(row).toContainText('13,50');
});
