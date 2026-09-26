import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { addCartItem, quoteCartShipping } from '@/features/cart/api';
import { demoAddress } from '@/mocks/data';
import { resetMockState } from '@/mocks/handlers';
import { server } from '@/mocks/server';
import type { CheckoutSummary } from '@/shared/api/types';
import { renderWithProviders } from '@/test/utils';
import { fetchCheckoutPreview } from './api';
import { CHECKOUT_STATE_KEY } from './hooks/useCheckoutState';
import CheckoutPage from './pages/CheckoutPage';

const FORBIDDEN = ['price', 'price_cents', 'unit_price_cents', 'line_total_cents', 'subtotal_cents', 'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents', 'shipping_price_cents', 'shipping_discount_cents', 'customer_id', 'status', 'payment_status', 'items', 'id', 'uuid', 'coupon_code'];
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

interface Captured {
  key: string | null;
  body: Record<string, unknown>;
}

async function prepare(): Promise<{ quoteId: string }> {
  resetMockState({ loggedIn: true });
  await addCartItem({ variant_id: 1, quantity: 5 });
  const quote = await quoteCartShipping({ address_uuid: demoAddress.uuid });
  window.sessionStorage.setItem(CHECKOUT_STATE_KEY, JSON.stringify({ addressUuid: demoAddress.uuid, quote, optionId: '2:2', paymentConfirmed: true, notes: '' }));
  return { quoteId: quote.quote_id! };
}

function renderReview() {
  return renderWithProviders(<CheckoutPage retryDelaysMs={[0, 0]} />, {
    route: '/checkout?passo=revisao',
    path: '/checkout',
    extraRoutes: [{ path: '/checkout/pedido/:uuid', element: <h1>Página do PIX</h1> }],
  });
}

const captured: Captured[] = [];
beforeEach(() => {
  captured.length = 0;
  server.events.on('request:start', async ({ request }) => {
    if (request.method === 'POST' && new URL(request.url).pathname === '/api/v1/checkout') {
      captured.push({ key: request.headers.get('Idempotency-Key'), body: (await request.clone().json()) as Record<string, unknown> });
    }
  });
});
afterEach(() => server.events.removeAllListeners());

async function confirm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('checkbox', { name: /Li e aceito os termos de compra/ }));
  const btn = await screen.findByRole('button', { name: /Confirmar pedido · R\$\s99,50/ });
  await user.click(btn);
}

describe('Checkout — revisão e confirmação', () => {
  it('envia Idempotency-Key (UUID) e apenas os campos da whitelist, sem preços', async () => {
    const { quoteId } = await prepare();
    const user = userEvent.setup();
    const { router } = renderReview();
    expect(await screen.findByRole('heading', { name: 'Revisão do pedido' })).toBeInTheDocument();
    expect(await screen.findByText(/5\sm × R\$\s15,90\s\/m/)).toBeInTheDocument();
    await confirm(user);
    await waitFor(() => expect(router.state.location.pathname).toMatch(/^\/checkout\/pedido\//));
    expect(captured).toHaveLength(1);
    const [{ key, body }] = captured;
    expect(key).toMatch(UUID_RE);
    expect(Object.keys(body).sort()).toEqual(['accept_terms', 'address_uuid', 'expected_total_cents', 'notes', 'payment_method', 'shipping_option_id', 'shipping_quote_id']);
    for (const f of FORBIDDEN) expect(body).not.toHaveProperty(f);
    expect(body).toEqual({ address_uuid: demoAddress.uuid, shipping_quote_id: quoteId, shipping_option_id: '2:2', payment_method: 'pix', expected_total_cents: 9950, notes: null, accept_terms: true });
    // estado do checkout e chave são descartados após o sucesso
    expect(window.sessionStorage.getItem(CHECKOUT_STATE_KEY)).toBeNull();
  });

  it('exige aceite dos termos antes de enviar', async () => {
    await prepare();
    const user = userEvent.setup();
    renderReview();
    await user.click(await screen.findByRole('button', { name: /Confirmar pedido/ }));
    expect(screen.getByText('Aceite os termos para continuar')).toBeInTheDocument();
    expect(captured).toHaveLength(0);
  });

  it('erro de rede: reenvia com a MESMA Idempotency-Key', async () => {
    await prepare();
    let calls = 0;
    server.use(
      http.post('*/api/v1/checkout', () => {
        calls += 1;
        if (calls === 1) return HttpResponse.error();
        return undefined; // segue para o handler padrão
      }),
    );
    const user = userEvent.setup();
    const { router } = renderReview();
    await confirm(user);
    await waitFor(() => expect(router.state.location.pathname).toMatch(/^\/checkout\/pedido\//));
    expect(captured).toHaveLength(2);
    expect(captured[0].key).toBe(captured[1].key);
  });

  it('409 price_changed: mostra o que mudou, atualiza o total e gera nova chave', async () => {
    const { quoteId } = await prepare();
    const current = await fetchCheckoutPreview({ addressUuid: demoAddress.uuid, quoteId, optionId: '2:2' });
    const changed: CheckoutSummary = {
      ...current,
      items: current.items.map((i) => ({ ...i, unit_price_cents: 1650, line_total_cents: 8250, warnings: [{ code: 'price_changed', previous_unit_price_cents: 1590, current_unit_price_cents: 1650 }] })),
      totals: { ...current.totals, subtotal_cents: 8250, total_cents: 10250 },
    };
    let first = true;
    server.use(
      http.post('*/api/v1/checkout', () => {
        if (first) {
          first = false;
          return HttpResponse.json({ message: 'Os valores do pedido mudaram. Revise e confirme novamente.', code: 'price_changed', summary: changed }, { status: 409 });
        }
        return HttpResponse.json({ message: 'ok' }, { status: 503 });
      }),
    );
    const user = userEvent.setup();
    renderReview();
    await confirm(user);
    const dialog = await screen.findByRole('dialog', { name: 'Algumas informações mudaram' });
    expect(within(dialog).getByText(/Vinil Adesivo Branco: R\$\s15,90 → R\$\s16,50/)).toBeInTheDocument();
    expect(within(dialog).getByText(/R\$\s102,50/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Revisar e confirmar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    const again = await screen.findByRole('button', { name: /Confirmar pedido · R\$\s102,50/ });
    await user.click(again);
    await waitFor(() => expect(captured.length).toBeGreaterThanOrEqual(2));
    expect(captured[1].body.expected_total_cents).toBe(10250);
    expect(captured[1].key).toMatch(UUID_RE);
    expect(captured[1].key).not.toBe(captured[0].key);
  });

  it('409 shipping_quote_expired: volta ao passo Frete com a nova cotação', async () => {
    await prepare();
    server.use(
      http.post('*/api/v1/checkout', () => HttpResponse.json({ message: 'A cotação de frete expirou.', code: 'shipping_quote_expired', shipping_quote: null }, { status: 409 })),
    );
    const user = userEvent.setup();
    renderReview();
    await confirm(user);
    expect(await screen.findByRole('heading', { name: 'Frete' })).toBeInTheDocument();
    expect(screen.getByText('A cotação de frete expirou.')).toBeInTheDocument();
  });
});
