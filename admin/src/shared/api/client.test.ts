import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import { api, buildQuery } from './client';
import { ApiError } from './errors';

describe('cliente HTTP', () => {
  it('serializa filtros conforme API §1.4', () => {
    expect(buildQuery({ status: ['paid', 'processing'], in_stock: true, q: '', page: 2, x: undefined })).toBe('?status=paid%2Cprocessing&in_stock=1&page=2');
  });

  it('envia X-XSRF-TOKEN e renova o CSRF em 419 (uma vez)', async () => {
    let calls = 0;
    const tokens: (string | null)[] = [];
    server.use(
      http.post('*/api/v1/admin/test', ({ request }) => {
        calls += 1;
        tokens.push(request.headers.get('X-XSRF-TOKEN'));
        if (calls === 1) return HttpResponse.json({ message: 'CSRF', code: 'csrf_token_mismatch' }, { status: 419 });
        return HttpResponse.json({ data: { ok: true } });
      }),
    );
    const res = await api.post<{ data: { ok: boolean } }>('/admin/test', {});
    expect(res.data.ok).toBe(true);
    expect(calls).toBe(2);
    expect(tokens.every((t) => t === 'mock-xsrf-token')).toBe(true);
  });

  it('normaliza 422 com errors por campo', async () => {
    server.use(http.post('*/api/v1/admin/test', () => HttpResponse.json({ message: 'Erro', errors: { 'variants.0.sku': ['SKU já usado.'] } }, { status: 422, headers: { 'X-Request-Id': 'req-1' } })));
    const err = await api.post('/admin/test', {}).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).fieldErrors).toEqual({ 'variants.0.sku': ['SKU já usado.'] });
    expect((err as ApiError).requestId).toBe('req-1');
  });
});
