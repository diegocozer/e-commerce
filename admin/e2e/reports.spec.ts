import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';

test('relatórios: vendas por período carrega e exporta CSV', async ({ page }) => {
  await page.goto('/admin/relatorios');
  await expect(page.getByRole('tab', { name: 'Vendas por período', selected: true })).toBeVisible();
  for (const kpi of ['Pedidos pagos', 'Faturamento', 'Ticket médio', 'Estornados']) {
    await expect(page.getByText(kpi, { exact: true }).first()).toBeVisible();
  }
  const table = page.getByRole('table', { name: 'Vendas por período' });
  await expect(table).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Faturamento' })).toBeVisible();

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar CSV' }).click()]);
  expect(download.suggestedFilename()).toMatch(/^sales_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/);
  const csv = (await readFile(await download.path())).toString('utf8');
  expect(csv.charCodeAt(0)).toBe(0xfeff); // BOM
  expect(csv.split(/\r?\n/)[0]).toContain(';');
});
