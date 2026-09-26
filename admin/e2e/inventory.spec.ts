import { expect, test, type Locator } from '@playwright/test';
import { selectOption } from './support/helpers';

const SKU = 'TIN-ECO-1L-K'; // Tinta Eco-solvente 1 L · Preto (UNIT, seed)

async function onHand(row: Locator): Promise<number> {
  const txt = await row.getByRole('cell').nth(2).innerText();
  return Number(txt.replace(/[^\d,]/g, '').replace(',', '.'));
}

test('estoque: entrada e ajuste com motivo obrigatório', async ({ page }) => {
  await page.goto(`/admin/estoque?q=${SKU}`);
  const row = page.getByRole('row').filter({ hasText: SKU });
  await expect(row).toHaveCount(1);
  const before = await onHand(row);

  // Entrada
  await row.getByRole('button', { name: 'Entrada' }).click();
  const entry = page.getByRole('dialog');
  await expect(entry).toContainText(`Entrada de estoque — ${SKU}`);
  await entry.locator('input[name="quantity"]').fill('10');
  await entry.getByRole('button', { name: 'Registrar entrada' }).click();
  await expect(entry.getByText('Selecione o motivo.')).toBeVisible();
  await selectOption(page, /Motivo/, 'Compra de fornecedor');
  await entry.locator('input[name="note"]').fill('NF 4521');
  await entry.getByRole('button', { name: 'Registrar entrada' }).click();
  await expect(entry).toBeHidden();
  await expect.poll(() => onHand(row)).toBe(before + 10);

  // Ajuste: motivo obrigatório
  await row.getByRole('button', { name: 'Ajustar' }).click();
  const adjust = page.getByRole('dialog');
  await expect(adjust).toContainText(`Ajustar estoque — ${SKU}`);
  await adjust.locator('input[name="new_on_hand"]').fill(String(before + 9));
  await expect(adjust.getByRole('status')).toContainText('Diferença: −1');
  await adjust.getByRole('button', { name: 'Registrar ajuste' }).click();
  await expect(adjust.getByText('Selecione o motivo.')).toBeVisible();
  await selectOption(page, /Motivo/, 'Outro');
  await adjust.getByRole('button', { name: 'Registrar ajuste' }).click();
  await expect(adjust.getByText(/Descreva o motivo \(mín/)).toBeVisible();
  await adjust.locator('input[name="note"]').fill('Contagem física E2E');
  const ack = adjust.getByLabel('Confirmo que conferi a contagem');
  if (await ack.isVisible()) await ack.check();
  await adjust.getByRole('button', { name: 'Registrar ajuste' }).click();
  await expect(adjust).toBeHidden();
  await expect.poll(() => onHand(row)).toBe(before + 9);

  // Histórico mostra o ajuste com o motivo
  await row.getByRole('button', { name: 'Histórico' }).click();
  const history = page.getByRole('region', { name: 'Histórico de movimentos' });
  await expect(history).toContainText('Contagem física E2E');
  await expect(history).toContainText('Compra de fornecedor — NF 4521');
});
