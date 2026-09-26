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
