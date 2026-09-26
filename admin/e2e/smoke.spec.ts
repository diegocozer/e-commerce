import { expect, test } from '@playwright/test';

// Todas as telas do super-admin abrem contra a API real sem respostas 4xx/5xx nem erros de JS.
const PATHS = [
  '/', '/produtos', '/produtos/novo', '/produtos/1', '/categorias', '/marcas', '/estoque', '/pedidos', '/clientes', '/clientes/1',
  '/empresas', '/empresas/1', '/precos/tabelas', '/precos/tabelas/1', '/precos/clientes', '/promocoes', '/cupons',
  '/frete/transportadoras', '/frete/metodos', '/frete/zonas', '/frete/zonas/1', '/frete/regras', '/frete/simulador',
  '/usuarios', '/papeis', '/configuracoes', '/auditoria', '/relatorios', '/conta/senha',
];

test('smoke: todas as telas carregam sem erros de API', async ({ page }) => {
  test.setTimeout(180_000);
  const problems: string[] = [];
  let current = '';
  page.on('response', (r) => {
    if (r.url().includes('/api/') && r.status() >= 400) problems.push(`${current}: ${r.status()} ${r.request().method()} ${r.url()}`);
  });
  page.on('pageerror', (e) => problems.push(`${current}: ${e.message}`));
  for (const path of PATHS) {
    current = path;
    await page.goto(`/admin${path}`);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).not.toContainText(/Não foi possível carregar|Algo deu errado/);
  }
  expect(problems).toEqual([]);
});
