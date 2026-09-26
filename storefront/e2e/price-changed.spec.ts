import { expect, test } from '@playwright/test';
import { brl, browserPost, devSql, loginAs } from './helpers';

// ADR-034: 409 price_changed traz um CheckoutSummary completo; a loja mostra o novo total
// e permite confirmar de novo sem recarregar.
const SKU = 'VIN-PT-122-FO';

test('checkout da Maria: preço muda na revisão → 409 price_changed → revisar e confirmar', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
  await loginAs(page, 'maria@example.com', 'password');

  await page.goto('/vinis/vinil-adesivo-preto-fosco-122m');
  const config = page.getByRole('region', { name: 'Configurar quantidade' });
  await page.getByLabel('Quantidade (metros)').fill('2');
  await expect(config.locator('[aria-busy="false"]').first()).toBeVisible();
  const added = page.waitForResponse((r) => r.url().endsWith('/api/v1/cart/items') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Adicionar ao carrinho' }).click();
  expect((await added).status()).toBe(201);

  await page.goto('/checkout');
  const main = page.getByRole('main');
  await expect(main.getByText(/Rua das Palmeiras/).first()).toBeVisible();
  await main.getByRole('button', { name: 'Continuar ›' }).click();
  await main.getByRole('radio', { name: /^Retirada na empresa/ }).check();
  await main.getByRole('button', { name: 'Continuar ›' }).click();
  await main.getByRole('button', { name: 'Continuar ›' }).click();
  const confirm = main.getByRole('button', { name: /^Confirmar pedido/ });
  await expect(confirm).toBeVisible();
  const before = (await confirm.textContent()) ?? '';
  await main.getByRole('checkbox', { name: /termos de compra/ }).check();

  let orderUuid = '';
  try {
    devSql(`update product_variants set price_cents = price_cents + 1000 where sku = '${SKU}'`);
    const conflict = page.waitForResponse((r) => r.url().endsWith('/api/v1/checkout') && r.request().method() === 'POST');
    await confirm.click();
    const res = await conflict;
    expect(res.status()).toBe(409);
    const body = (await res.json()) as { code: string; summary: Record<string, unknown> };
    expect(body.code).toBe('price_changed');
    for (const key of ['items', 'totals', 'address', 'shipping_option', 'billing', 'can_place_order', 'blocking', 'payment_expires_in_minutes']) {
      expect(body.summary, `summary.${key}`).toHaveProperty(key);
    }
    const newTotal = (body.summary.totals as { total_cents: number }).total_cents;
    const newTotalText = (newTotal / 100).toFixed(2).replace('.', ',');

    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText(/Novo total/);
    await expect(dialog.getByText(brl(newTotalText)).first()).toBeVisible();
    await dialog.getByRole('button', { name: 'Revisar e confirmar' }).click();
    await expect(confirm).toHaveText(brl(newTotalText));
    expect(await confirm.textContent()).not.toBe(before);

    await confirm.click();
    await expect(page).toHaveURL(/\/checkout\/pedido\/[0-9a-f-]{36}/, { timeout: 30_000 });
    orderUuid = page.url().split('/').pop()!.split('?')[0];
    await expect(page.getByText(/^Valor:/)).toHaveText(brl(newTotalText));
  } finally {
    devSql(`update product_variants set price_cents = price_cents - 1000 where sku = '${SKU}'`);
    // Não acumula pedidos pendentes da Maria (limite de 3 — RN): aprova no sandbox.
    if (orderUuid) expect(await browserPost(page, `/api/v1/dev/payments/${orderUuid}/approve`)).toBe(202);
  }
});
