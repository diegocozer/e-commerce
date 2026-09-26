import { http as mswHttp, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import { CART_TOKEN_KEY } from './cartToken';
import { getData, http, postData } from './client';
import { ApiError, describeError } from './errors';

describe('cliente HTTP', () => {
  it('419: renova o CSRF e repete uma vez', async () => {
    let calls = 0;
    let csrf = 0;
    server.use(
      mswHttp.get('*/sanctum/csrf-cookie', () => { csrf += 1; return new HttpResponse(null, { status: 204 }); }),
      mswHttp.post('*/api/v1/auth/forgot-password', () => {
        calls += 1;
        return calls === 1 ? HttpResponse.json({ message: 'CSRF', code: 'csrf_token_mismatch' }, { status: 419 }) : HttpResponse.json({ data: { message: 'ok' } });
      }),
    );
    await expect(postData('/auth/forgot-password', { email: 'a@b.com' })).resolves.toEqual({ message: 'ok' });
    expect(calls).toBe(2);
    expect(csrf).toBeGreaterThanOrEqual(1);
  });

  it('404 cart_not_found: apaga o token e repete sem X-Cart-Token', async () => {
    window.localStorage.setItem(CART_TOKEN_KEY, '11111111-1111-4111-8111-111111111111');
    const seen: (string | null)[] = [];
    server.events.on('request:start', ({ request }) => {
      if (new URL(request.url).pathname === '/api/v1/cart') seen.push(request.headers.get('X-Cart-Token'));
    });
    const cart = await getData<{ items: unknown[] }>('/cart');
    expect(cart.items).toEqual([]);
    expect(seen).toEqual(['11111111-1111-4111-8111-111111111111', null]);
    expect(window.localStorage.getItem(CART_TOKEN_KEY)).toBeNull();
    server.events.removeAllListeners();
  });

  it('não envia X-Cart-Token fora de /cart, /auth/login e /auth/register', async () => {
    window.localStorage.setItem(CART_TOKEN_KEY, '11111111-1111-4111-8111-111111111111');
    let header: string | null = 'x';
    server.events.on('request:start', ({ request }) => {
      if (request.url.includes('/settings/public')) header = request.headers.get('X-Cart-Token');
    });
    await getData('/settings/public');
    expect(header).toBeNull();
    server.events.removeAllListeners();
  });

  it('normaliza 422 e códigos de erro com mensagem pt-BR e request id', async () => {
    server.use(
      mswHttp.get('*/api/v1/boom', () => HttpResponse.json({ message: 'x' }, { status: 500, headers: { 'X-Request-Id': 'req-123' } })),
      mswHttp.get('*/api/v1/limit', () => HttpResponse.json({ message: 'Too many', code: 'too_many_requests' }, { status: 429, headers: { 'Retry-After': '30' } })),
    );
    const e500 = await http.get('/boom').catch((e: unknown) => e);
    expect(e500).toBeInstanceOf(ApiError);
    expect((e500 as ApiError).code).toBe('server_error');
    expect(describeError(e500)).toBe('Algo deu errado do nosso lado. Tente novamente em instantes. Código para suporte: req-123');
    const e429 = await http.get('/limit').catch((e: unknown) => e);
    expect(describeError(e429)).toBe('Muitas tentativas seguidas. Aguarde 30 s e tente novamente.');
    const e422 = await postData('/products/vinil-adesivo-branco-122m/price-preview', { variant_id: 1, quantity: 5.05 }).catch((e: unknown) => e);
    expect((e422 as ApiError).fieldErrors.quantity[0].replace(/ /g, ' ')).toBe('Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.');
    expect((e422 as ApiError).details.quantity.suggestions).toEqual([5, 5.1]);
  });
});
