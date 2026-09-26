import { expect, test } from '@playwright/test';
import { adminApi, selectOption, uniq } from './support/helpers';

let zoneId: number | null = null;

test.afterAll(async () => {
  if (zoneId === null) return;
  const s = await adminApi();
  const rules = await s.ok<{ data: { id: number; zone_id: number | null }[] }>('GET', `/admin/shipping/rules?zone_id=${zoneId}`);
  for (const r of rules.data.filter((x) => x.zone_id === zoneId)) await s.call('DELETE', `/admin/shipping/rules/${r.id}`);
  await s.call('DELETE', `/admin/shipping/zones/${zoneId}`);
  await s.dispose();
});

test('frete: zona com faixa de CEP, regra table_rate e simulador com trace', async ({ page }) => {
  const tag = uniq();
  const zoneName = `Zona E2E ${tag}`;
  const ruleName = `Tabela E2E ${tag}`;

  // Zona
  await page.goto('/admin/frete/zonas');
  await page.getByRole('link', { name: 'Nova zona' }).click();
  await page.locator('input[name="name"]').fill(zoneName);
  await page.getByRole('button', { name: 'Adicionar faixa' }).click();
  await page.getByLabel('CEP inicial').fill('89010000');
  await page.getByLabel('CEP final').fill('89010999');
  await page.getByRole('button', { name: 'Salvar zona' }).click();
  await expect(page).toHaveURL(/\/admin\/frete\/zonas\/\d+$/);
  zoneId = Number(page.url().split('/').pop());
  await expect(page.getByText(`Zona: ${zoneName}`)).toBeVisible();
  await page.getByLabel('CEP', { exact: true }).fill('89010-000');
  await page.getByRole('button', { name: 'Testar' }).click();
  await expect(page.getByRole('status')).toBeVisible();

  // Regra table_rate nesta zona
  await page.goto('/admin/frete/regras');
  await page.getByRole('button', { name: 'Nova regra' }).click();
  const dialog = page.getByRole('dialog');
  await selectOption(page, /^Método/, 'Frete por CEP');
  await selectOption(page, /^Zona/, zoneName);
  await dialog.locator('input[name="priority"]').fill('1');
  await dialog.locator('input[name="name"]').fill(ruleName);
  await selectOption(page, /Tipo de preço/, 'Valor fixo');
  await dialog.locator('input[name="price_cents"]').fill('17,77');
  await dialog.locator('input[name="delivery_days_min"]').fill('2');
  await dialog.locator('input[name="delivery_days_max"]').fill('4');
  await dialog.getByRole('button', { name: 'Salvar regra' }).click();
  // Com avisos (ex.: desempate) o dialog fica aberto mostrando-os.
  await expect(dialog.getByRole('alert').or(page.getByText('Regra salva').first()).first()).toBeVisible();
  if (await dialog.isVisible()) await page.keyboard.press('Escape');
  await expect(page.getByRole('table', { name: `Regras de Frete por CEP em ${zoneName}` })).toContainText(ruleName);

  // Simulador
  await page.goto('/admin/frete/simulador');
  await page.getByLabel(/CEP de destino/).fill('89010000');
  await page.getByRole('textbox', { name: 'Peso' }).fill('2');
  await page.getByRole('button', { name: 'Simular' }).click();
  await expect(page.getByText(/Destino: Blumenau\/SC/)).toBeVisible();
  await expect(page.getByText(zoneName).first()).toBeVisible();
  const options = page.getByRole('table').filter({ hasText: 'Opção' });
  await expect(options).toContainText('Frete por CEP');
  const trace = page.getByRole('list', { name: 'Trace da avaliação' });
  await expect(trace).toContainText('Frete por CEP');
  await expect(trace).toContainText(new RegExp(`${ruleName} \\(prior\\. 1\\) — casou`));
});
