import { execFileSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';

/** Espaço comum ou NBSP entre "R$" e o valor (Intl pt-BR usa NBSP). */
export function brl(value: string): RegExp {
  return new RegExp(`R\\$[\\s\\u00a0]*${value.replace(/[.,]/g, (c) => `\\${c}`)}`);
}

/** Registra respostas /api com erro (≥ 400) — ajuda a diagnosticar drift de contrato. */
export function logApiErrors(page: Page, allow: (url: string, status: number) => boolean = () => false): string[] {
  const errors: string[] = [];
  page.on('response', async (res) => {
    const url = res.url();
    if (!url.includes('/api/') || res.status() < 400 || allow(url, res.status())) return;
    let body = '';
    try {
      body = (await res.text()).slice(0, 400);
    } catch {
      /* corpo indisponível */
    }
    errors.push(`${res.request().method()} ${url} → ${res.status()} ${body}`);
  });
  return errors;
}

export function uniqueEmail(prefix = 'e2e'): string {
  return `${prefix}+${Date.now()}${Math.floor(Math.random() * 1000)}@example.com`;
}

/** CPF válido gerado a partir de 9 dígitos aleatórios. */
export function randomCpf(): string {
  const base = Array.from({ length: 9 }, () => Math.floor(Math.random() * 10));
  if (base.every((d) => d === base[0])) base[0] = (base[0] + 1) % 10;
  const dv = (digits: number[]) => {
    const sum = digits.reduce((acc, d, i) => acc + d * (digits.length + 1 - i), 0);
    const r = (sum * 10) % 11;
    return r === 10 ? 0 : r;
  };
  const d1 = dv(base);
  const d2 = dv([...base, d1]);
  return [...base, d1, d2].join('');
}

export async function searchAndOpenProduct(page: Page, term: string, productName: string): Promise<void> {
  const search = page.getByRole('combobox', { name: 'Buscar produtos por nome ou SKU' }).first();
  await search.fill(term);
  await search.press('Enter');
  await expect(page).toHaveURL(/\/busca\?q=/);
  await page.getByRole('link', { name: productName }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: productName })).toBeVisible();
}

/** POST autenticado pela sessão do navegador (cookies + X-XSRF-TOKEN), fora da UI. */
export async function browserPost(page: Page, path: string, body: unknown = {}): Promise<number> {
  return page.evaluate(
    async ({ url, payload }) => {
      const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify(payload),
      });
      return res.status;
    },
    { url: path, payload: body },
  );
}

/** SQL direto no banco de desenvolvimento (simula mudanças feitas pelo admin durante o checkout). */
export function devSql(sql: string): void {
  const db = process.env.E2E_DB_NAME ?? 'ecommerce';
  execFileSync('psql', ['-h', process.env.E2E_DB_HOST ?? '127.0.0.1', '-U', process.env.E2E_DB_USER ?? 'ecommerce', '-d', db, '-v', 'ON_ERROR_STOP=1', '-c', sql], {
    env: { ...process.env, PGPASSWORD: process.env.E2E_DB_PASSWORD ?? 'secret' },
    stdio: 'pipe',
  });
}

export async function loginAs(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/entrar');
  const form = page.getByRole('main');
  await form.getByRole('textbox', { name: 'E-mail' }).fill(email);
  await form.getByRole('textbox', { name: 'Senha' }).fill(password);
  await form.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).not.toHaveURL(/\/entrar/);
}
