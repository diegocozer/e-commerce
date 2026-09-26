import { expect, test } from '@playwright/test';
import { placePaidOrder } from './support/helpers';

test('pedido pago via API da loja: separação → envio com rastreio → entrega no painel', async ({ page }) => {
  const order = await placePaidOrder();

  await page.goto(`/admin/pedidos?q=${order.number}`);
  const row = page.getByRole('row').filter({ hasText: order.number });
  await expect(row).toContainText('Pago');
  await row.click();
  await expect(page).toHaveURL(/\/admin\/pedidos\/\d+$/);
  await expect(page.getByRole('heading', { name: new RegExp(order.number) })).toBeVisible();
  await expect(page.getByText('Pago', { exact: true }).first()).toBeVisible();
  const timeline = page.getByRole('list', { name: 'Linha do tempo do pedido' });
  await expect(timeline).toContainText('Pagamento PIX aprovado.');

  // paid → processing
  await page.getByRole('button', { name: 'Marcar em separação' }).click();
  let dialog = page.getByRole('dialog');
  await expect(dialog).toContainText(`Marcar em separação — ${order.number}`);
  await dialog.getByRole('button', { name: 'Marcar em separação' }).click();
  await expect(dialog).toBeHidden();
  await expect(timeline.getByRole('listitem').filter({ hasText: 'Em separação' })).toBeVisible();

  // processing → shipped com código de rastreio
  const tracking = `BR${Date.now().toString().slice(-9)}E2E`;
  await page.getByRole('button', { name: 'Marcar como enviado' }).click();
  dialog = page.getByRole('dialog');
  await dialog.getByLabel(/Código de rastreio/).fill(tracking);
  await dialog.getByLabel('Observação').fill('Enviado pela transportadora regional.');
  await dialog.getByRole('button', { name: 'Marcar como enviado' }).click();
  await expect(dialog).toBeHidden();
  await expect(timeline.getByRole('listitem').filter({ hasText: 'Enviado' })).toContainText('Enviado pela transportadora regional.');
  await expect(page.getByText(tracking).first()).toBeVisible();

  // shipped → delivered
  await page.getByRole('button', { name: 'Marcar como entregue' }).click();
  dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Marcar como entregue' }).click();
  await expect(dialog).toBeHidden();
  await expect(timeline.getByRole('listitem').filter({ hasText: 'Entregue' })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Marcar/ })).toHaveCount(0);
  await expect(timeline.getByRole('listitem')).toHaveCount(5);

  // Lista reflete o status final
  await page.goto(`/admin/pedidos?q=${order.number}`);
  await expect(page.getByRole('row').filter({ hasText: order.number })).toContainText('Entregue');
});
