import { expect, test } from '@playwright/test';
import { brl, browserPost, logApiErrors, randomCpf, searchAndOpenProduct, uniqueEmail } from './helpers';

// Fluxo de aceite ponta a ponta contra o backend real (API.md / BUSINESS_RULES).
test('compra PF: busca → produto → carrinho → frete → cadastro → checkout PIX → pago', async ({ page }) => {
  const apiErrors = logApiErrors(page, (url, status) => status === 401 && url.endsWith('/api/v1/me'));

  await page.goto('/');
  await page.getByRole('button', { name: 'Somente essenciais' }).click();
  await expect(page.getByRole('combobox', { name: 'Buscar produtos por nome ou SKU' }).first()).toBeVisible();

  // Busca → produto
  await searchAndOpenProduct(page, 'Vinil Adesivo Branco', 'Vinil Adesivo Branco');

  // 5 m → R$ 79,50
  const qty = page.getByLabel('Quantidade (metros)');
  await qty.fill('5');
  const config = page.getByRole('region', { name: 'Configurar quantidade' });
  await expect(config.getByLabel(/^Total:/)).toHaveText(brl('79,50'));

  const added = page.waitForResponse((r) => r.url().endsWith('/api/v1/cart/items') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Adicionar ao carrinho' }).click();
  expect((await added).status()).toBe(201);

  // Carrinho + frete por CEP
  await page.goto('/carrinho');
  await expect(page.getByRole('heading', { level: 1 })).toContainText(/carrinho/i);
  await expect(page.getByText('Vinil Adesivo Branco').first()).toBeVisible();
  await page.getByLabel(/CEP/).first().fill('89010-000');
  await page.getByRole('button', { name: 'Calcular' }).click();
  const shipping = page.getByRole('group', { name: 'Opções de frete' });
  await expect(shipping.getByRole('radio', { name: /^Retirada na empresa Grátis/ })).toBeVisible();
  await expect(shipping.getByRole('radio', { name: /^Entrega própria R\$[\s ]*20,00/ })).toBeVisible();

  // Cadastro PF
  await page.getByRole('link', { name: 'Finalizar compra' }).or(page.getByRole('button', { name: 'Finalizar compra' })).first().click();
  await expect(page).toHaveURL(/\/entrar/);
  await page.getByRole('main').getByRole('link', { name: 'Criar conta' }).click();
  await expect(page).toHaveURL(/\/cadastro/);
  const email = uniqueEmail();
  await page.getByRole('button', { name: 'Pessoa física' }).click();
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'Nome completo' }).fill('Cliente E2E Teste');
  await form.getByRole('textbox', { name: 'CPF', exact: true }).fill(randomCpf());
  await form.getByRole('textbox', { name: 'Telefone/WhatsApp' }).fill('47999998888');
  await form.getByRole('textbox', { name: 'E-mail' }).fill(email);
  await form.getByRole('textbox', { name: 'Senha', exact: true }).fill('SenhaForte#2026');
  await form.getByRole('textbox', { name: 'Confirmar senha' }).fill('SenhaForte#2026');
  await page.getByRole('checkbox', { name: /termos/i }).check();
  await page.getByRole('button', { name: /Criar conta/i }).click();

  // Checkout — endereço (novo cadastro não tem endereço: formulário inline)
  await expect(page).toHaveURL(/\/checkout/, { timeout: 20_000 });
  const main = page.getByRole('main');
  await main.getByRole('textbox', { name: 'CEP' }).fill('89010-000');
  await expect(main.getByRole('textbox', { name: 'Cidade/UF' })).toHaveValue('Blumenau/SC');
  await expect(main.getByRole('textbox', { name: 'Rua' })).toHaveValue('Rua XV de Novembro');
  await main.getByRole('textbox', { name: 'Número' }).fill('500');
  await main.getByRole('button', { name: /Salvar|Usar este endereço|Adicionar/ }).click();
  await main.getByRole('button', { name: 'Continuar ›' }).click();

  // Frete: entrega própria
  await main.getByRole('radio', { name: /^Entrega própria/ }).check();
  await main.getByRole('button', { name: 'Continuar ›' }).click();

  // Pagamento: PIX
  await expect(main.getByText(/PIX/).first()).toBeVisible();
  await main.getByRole('button', { name: 'Continuar ›' }).click();

  // Revisão: total R$ 99,50
  const confirm = main.getByRole('button', { name: /^Confirmar pedido/ });
  await expect(confirm).toHaveText(brl('99,50'));
  await main.getByRole('checkbox', { name: /termos de compra/ }).check();
  await confirm.click();

  // Tela do PIX
  await expect(page).toHaveURL(/\/checkout\/pedido\/[0-9a-f-]{36}/, { timeout: 30_000 });
  const orderUuid = page.url().split('/').pop()!.split('?')[0];
  await expect(page.getByRole('button', { name: 'Copiar código PIX' })).toBeVisible();

  // Aprova via endpoint de sandbox (fora da UI) e espera o polling mostrar "pago"
  const approveStatus = await browserPost(page, `/api/v1/dev/payments/${orderUuid}/approve`);
  expect(approveStatus).toBe(202);
  await expect(page.getByText(/Pedido .+ pago\./)).toBeVisible({ timeout: 30_000 });

  // Conta → pedidos → detalhe
  await page.goto('/conta/pedidos');
  const row = page.getByRole('link', { name: /^Ver detalhes do pedido CV-/ }).first();
  await expect(page.getByRole('main').getByText(/Pago/).first()).toBeVisible();
  await expect(page.getByRole('main').getByText(brl('99,50')).first()).toBeVisible();
  await row.click();
  await expect(page).toHaveURL(new RegExp(`/conta/pedidos/${orderUuid}`));
  const detail = page.getByRole('main');
  await expect(detail.getByText(brl('99,50')).first()).toBeVisible();
  const timeline = detail.getByRole('list', { name: 'Acompanhamento do pedido' });
  await expect(detail.getByRole('heading', { level: 1, name: /^Pedido CV-/ })).toBeVisible();
  await expect(detail.getByText('Pago', { exact: true }).first()).toBeVisible();
  await expect(timeline.getByRole('listitem').filter({ hasText: 'Pedido realizado' })).toContainText(/\d{2}\/\d{2}\/\d{4}/);
  await expect(timeline.getByRole('listitem').filter({ hasText: 'Pagamento aprovado' })).toContainText(/\d{2}\/\d{2}\/\d{4}/);
  await expect(detail.getByText(/PIX · Aprovado em/)).toBeVisible();

  // Visão geral: "Compre de novo" vem de GET /me/reorder-suggestions (pedido pago)
  await page.goto('/conta');
  await expect(page.getByRole('button', { name: 'Adicionar Vinil Adesivo Branco (5 m) ao carrinho' })).toBeVisible();

  expect(apiErrors, apiErrors.join('\n')).toEqual([]);
});
