import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, request as pwRequest, type APIRequestContext, type Page } from '@playwright/test';

export const ADMIN = { email: 'admin@example.com', password: 'password' };
/** Sessão do super-admin gravada pelo projeto `setup` (auth.setup.ts). */
export const ADMIN_STATE = 'e2e/.auth/admin.json';
export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5174';
const BACKEND_DIR = fileURLToPath(new URL('../../../backend', import.meta.url));

/** Sufixo curto e único por execução (nomes/SKUs/e-mails não colidem entre execuções). */
export const uniq = (): string => `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`.toUpperCase();

export async function login(page: Page, email = ADMIN.email, password = ADMIN.password): Promise<void> {
  await page.goto('/admin/entrar');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/entrar'));
}

/** Cliente HTTP stateful (Sanctum SPA): cookies + X-XSRF-TOKEN, via proxy do Vite do painel. */
export class ApiSession {
  private constructor(private readonly ctx: APIRequestContext) {}

  static async create(storageState?: string): Promise<ApiSession> {
    const ctx = await pwRequest.newContext({
      baseURL: BASE_URL,
      storageState,
      extraHTTPHeaders: { Accept: 'application/json', Origin: BASE_URL, Referer: `${BASE_URL}/admin/` },
    });
    await ctx.get('/sanctum/csrf-cookie');
    return new ApiSession(ctx);
  }

  private async xsrf(): Promise<string> {
    const { cookies } = await this.ctx.storageState();
    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  }

  async call<T = unknown>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}): Promise<{ status: number; json: T }> {
    const res = await this.ctx.fetch(`/api/v1${path}`, {
      method,
      data: body,
      headers: { 'X-XSRF-TOKEN': await this.xsrf(), ...headers },
    });
    const text = await res.text();
    return { status: res.status(), json: (text ? JSON.parse(text) : null) as T };
  }

  /** Igual a `call`, mas falha o teste se o status não for o esperado. */
  async ok<T = unknown>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}, expected = [200, 201, 202, 204]): Promise<T> {
    const r = await this.call<T>(method, path, body, headers);
    expect(expected, `${method} ${path} → ${r.status} ${JSON.stringify(r.json)}`).toContain(r.status);
    return r.json;
  }

  async dispose(): Promise<void> {
    await this.ctx.dispose();
  }
}

/** CPF válido aleatório (DV calculado). */
export function randomCpf(): string {
  const d = Array.from({ length: 9 }, () => Math.floor(Math.random() * 10));
  if (d.every((x) => x === d[0])) d[0] = (d[0] + 1) % 10;
  const dv = (arr: number[]) => {
    const s = arr.reduce((acc, n, i) => acc + n * (arr.length + 1 - i), 0);
    const r = (s * 10) % 11;
    return r === 10 ? 0 : r;
  };
  d.push(dv(d));
  d.push(dv(d));
  return d.join('');
}

/**
 * Roda um comando artisan no backend usando o mesmo banco do servidor:
 * E2E_DB_DATABASE (ou DB_DATABASE) quando definido; senão o `.env` do backend.
 */
export function artisan(args: string[]): string {
  const db = process.env.E2E_DB_DATABASE ?? process.env.DB_DATABASE;
  return execFileSync('php', ['artisan', ...args], {
    cwd: BACKEND_DIR,
    env: { ...process.env, ...(db ? { DB_DATABASE: db } : {}) },
    stdio: 'pipe',
    timeout: 60_000,
  }).toString();
}

/** Processa a fila (o webhook do sandbox é tratado pelo job ProcessWebhookEvent na fila `webhooks`). */
export function runQueue(): void {
  artisan(['queue:work', '--queue=webhooks,default,notifications', '--stop-when-empty', '--tries=1']);
}

/** Define a senha de um admin convidado (o convite real envia link por e-mail). */
export function setAdminPassword(email: string, password: string): void {
  artisan([
    'tinker',
    '--execute',
    `App\\Modules\\Identity\\Models\\AdminUser::query()->where('email', ${JSON.stringify(email).replace(/"/g, "'")})->firstOrFail()->forceFill(['password' => '${password}'])->save();`,
  ]);
}

const CUSTOMER = { email: 'cliente.e2e.painel@example.com', password: 'Vinil2026e2e' };

interface CheckoutResult {
  order: { uuid: string; number: string };
}

/**
 * Fluxo completo do cliente pela API da loja: cadastro → carrinho (vinil 5 m) → cotação de frete →
 * checkout PIX com Idempotency-Key → aprovação no sandbox. Retorna o número do pedido.
 */
export async function placePaidOrder(): Promise<{ number: string; uuid: string }> {
  const s = await ApiSession.create();
  try {
    // Cliente fixo de E2E: login; na primeira execução (banco novo), cadastro. Evita o rate limit de cadastro (5/min, 20/h).
    const login = await s.call('POST', '/auth/login', { email: CUSTOMER.email, password: CUSTOMER.password });
    if (login.status === 422) {
      const settings = await s.ok<{ data: { terms_version: string } }>('GET', '/settings/public');
      await s.ok('POST', '/auth/register', {
        type: 'individual',
        name: 'Cliente Teste E2E',
        cpf: randomCpf(),
        email: CUSTOMER.email,
        phone: '47999990000',
        password: CUSTOMER.password,
        password_confirmation: CUSTOMER.password,
        accept_terms: true,
        terms_version: settings.data.terms_version,
      });
    } else {
      expect(login.status, JSON.stringify(login.json)).toBe(200);
      await s.call('DELETE', '/cart'); // carrinho limpo a cada execução (404 se não houver)
    }
    const addresses = await s.ok<{ data: { uuid: string; postal_code: string }[] }>('GET', '/me/addresses');
    const address = addresses.data.find((a) => a.postal_code.replace(/\D/g, '') === '89010000')
      ?? (await s.ok<{ data: { uuid: string } }>('POST', '/me/addresses', {
        postal_code: '89010-000',
        street: 'Rua XV de Novembro',
        number: '500',
        district: 'Centro',
        recipient_name: 'Cliente Teste E2E',
        phone: '47999990000',
      })).data;
    const product = await s.ok<{ data: { variants: { id: number }[] } }>('GET', '/products/vinil-adesivo-branco-122m');
    await s.ok('POST', '/cart/items', { variant_id: product.data.variants[0].id, quantity: 5 });
    const quote = await s.ok<{ data: { quote_id: string; options: { option_id: string; method_type: string }[] } }>('POST', '/cart/shipping-quote', { postal_code: '89010-000' });
    const option = quote.data.options.find((o) => o.method_type !== 'pickup') ?? quote.data.options[0];
    const body = { address_uuid: address.uuid, shipping_quote_id: quote.data.quote_id, shipping_option_id: option.option_id, payment_method: 'pix' };
    const preview = await s.ok<{ data: { totals: { total_cents: number }; can_place_order: boolean; blocking: unknown[] } }>('POST', '/checkout/preview', body);
    expect(preview.data.can_place_order, JSON.stringify(preview.data.blocking)).toBe(true);
    const checkout = await s.ok<{ data: CheckoutResult }>(
      'POST',
      '/checkout',
      { ...body, accept_terms: true, expected_total_cents: preview.data.totals.total_cents },
      { 'Idempotency-Key': randomUUID() },
    );
    const { uuid, number } = checkout.data.order;
    await s.ok('POST', `/dev/payments/${uuid}/approve`);
    runQueue();
    return { uuid, number };
  } finally {
    await s.dispose();
  }
}

/** MUI `TextField select`: abre o combobox pelo rótulo e escolhe a opção. */
export async function selectOption(page: Page, label: string | RegExp, option: string | RegExp): Promise<void> {
  await page.getByRole('combobox', { name: label }).click();
  await page.getByRole('option', { name: option }).first().click();
}

/** Sessão da API do painel como super-admin (para preparar/limpar dados fora da UI), sem novo login. */
export function adminApi(): Promise<ApiSession> {
  return ApiSession.create(ADMIN_STATE);
}
