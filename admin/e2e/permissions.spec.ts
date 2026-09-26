import { expect, test } from '@playwright/test';
import { ADMIN_STATE, adminApi, ApiSession, login, setAdminPassword, uniq } from './support/helpers';

test('permissões: vendedor convidado vê menu restrito, sem ações e com 403', async ({ browser }) => {
  const tag = uniq().toLowerCase();
  const email = `vendedor.${tag}@example.com`;
  const password = 'Vendedor#2026e2e';

  // Super-admin convida um vendedor pelo painel
  const adminCtx = await browser.newContext({ storageState: ADMIN_STATE });
  const admin = await adminCtx.newPage();
  await admin.goto('/admin/usuarios');
  await admin.getByRole('button', { name: 'Convidar usuário' }).click();
  const dialog = admin.getByRole('dialog');
  await dialog.locator('input[name="name"]').fill(`Vendedor E2E ${tag}`);
  await dialog.locator('input[name="email"]').fill(email);
  await dialog.getByRole('combobox', { name: /Papéis/ }).click();
  await admin.getByRole('option', { name: 'Vendedor' }).click();
  await dialog.getByRole('button', { name: 'Enviar convite' }).click();
  await expect(dialog).toBeHidden();
  await admin.goto(`/admin/usuarios?q=${encodeURIComponent(email)}`);
  await expect(admin.getByRole('row').filter({ hasText: email })).toContainText('Vendedor');
  await adminCtx.close();

  setAdminPassword(email, password);

  // Vendedor
  const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await ctx.newPage();
  await login(page, email, password);
  const nav = page.getByRole('navigation', { name: 'Menu principal' });
  for (const visible of ['Dashboard', 'Produtos', 'Estoque', 'Pedidos', 'Clientes', 'Cupons', 'Relatórios']) {
    await expect(nav.getByRole('link', { name: new RegExp(`^${visible}`) })).toBeVisible();
  }
  for (const hidden of ['Transportadoras', 'Regras', 'Simulador', 'Configurações', 'Usuários', 'Papéis & permissões', 'Logs de auditoria']) {
    await expect(nav.getByRole('link', { name: hidden })).toHaveCount(0);
  }

  // Sem products.manage: sem "Novo produto"; sem inventory.move/adjust: sem Entrada/Ajustar
  await page.goto('/admin/produtos');
  await expect(page.getByRole('heading', { name: /Produtos/ })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Novo produto' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Novo produto' })).toHaveCount(0);
  await page.goto('/admin/estoque');
  await expect(page.getByRole('button', { name: 'Histórico' }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Entrada' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Ajustar' })).toHaveCount(0);

  // Rotas sem permissão → página 403
  for (const path of ['/admin/usuarios', '/admin/frete/regras', '/admin/produtos/novo']) {
    await page.goto(path);
    await expect(page.getByText('403 — Acesso negado')).toBeVisible();
  }
  await ctx.close();

  // Backend continua sendo a autoridade
  const api = await ApiSession.create();
  await api.ok('POST', '/admin/auth/login', { email, password });
  const zones = await api.call<{ code: string }>('GET', '/admin/shipping/zones');
  expect(zones.status).toBe(403);
  expect(zones.json.code).toBe('forbidden');
  await api.dispose();

  // Limpeza: desativa o usuário criado
  const s = await adminApi();
  const users = await s.ok<{ data: { id: number; email: string }[] }>('GET', `/admin/users?q=${encodeURIComponent(email)}`);
  const u = users.data.find((x) => x.email === email);
  if (u) await s.call('POST', `/admin/users/${u.id}/deactivate`);
  await s.dispose();
});
