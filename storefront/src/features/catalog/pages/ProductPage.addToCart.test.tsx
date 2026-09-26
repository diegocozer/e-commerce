import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MiniCartDrawer } from '@/features/cart';
import { server } from '@/mocks/server';
import { CART_TOKEN_KEY } from '@/shared/api/cartToken';
import { renderWithProviders } from '@/test/utils';
import ProductPage from './ProductPage';

const route = '/vinis/vinil-adesivo-branco-122m';
const path = '/:categorySlug/:productSlug';

function Page() {
  return (
    <>
      <ProductPage />
      <MiniCartDrawer />
    </>
  );
}

afterEach(() => {
  server.events.removeAllListeners();
  vi.unstubAllGlobals();
});

describe('Página de produto — adicionar ao carrinho', () => {
  it('envia só variante + quantidade, guarda o X-Cart-Token e mostra snackbar', async () => {
    const bodies: unknown[] = [];
    const cartHeaders: (string | null)[] = [];
    server.events.on('request:start', async ({ request }) => {
      if (request.method === 'POST' && request.url.endsWith('/cart/items')) {
        bodies.push(await request.clone().json());
        cartHeaders.push(request.headers.get('X-Cart-Token'));
      }
    });
    const user = userEvent.setup();
    renderWithProviders(<Page />, { route, path });
    expect(await screen.findByRole('heading', { level: 1, name: 'Vinil Adesivo Branco' })).toBeInTheDocument();
    expect(screen.getAllByText(/SKU VIN-BR-122-BR/).length).toBeGreaterThan(0);
    // descrição sanitizada (o <script> do fixture é removido)
    expect(document.querySelector('main script, section script')).toBeNull();

    const input = screen.getByRole('spinbutton', { name: /Quantidade \(metros\)/ });
    await user.clear(input);
    await user.type(input, '5');
    const cta = screen.getAllByRole('button', { name: /Adicionar ao carrinho/ })[0];
    await waitFor(() => expect(cta).toBeEnabled());
    await user.click(cta);

    expect(await screen.findByText(/Vinil Adesivo Branco — 5\sm adicionado ao carrinho/)).toBeInTheDocument();
    expect(bodies).toEqual([{ variant_id: 1, quantity: 5 }]);
    expect(cartHeaders).toEqual([null]);
    expect(window.localStorage.getItem(CART_TOKEN_KEY)).toMatch(/^[0-9a-f-]{36}$/);

    // segunda adição reutiliza o token (API §1.3) e soma a linha
    await user.click(cta);
    await waitFor(() => expect(cartHeaders).toHaveLength(2));
    expect(cartHeaders[1]).toBe(window.localStorage.getItem(CART_TOKEN_KEY));
  });

  it('abre o mini-carrinho no desktop com o item destacado', async () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('min-width'), media: query, onchange: null, addListener: () => undefined, removeListener: () => undefined, addEventListener: () => undefined, removeEventListener: () => undefined, dispatchEvent: () => false }));
    const user = userEvent.setup();
    renderWithProviders(<Page />, { route, path });
    await screen.findByRole('heading', { level: 1, name: 'Vinil Adesivo Branco' });
    const cta = screen.getAllByRole('button', { name: /Adicionar ao carrinho/ })[0];
    await waitFor(() => expect(cta).toBeEnabled());
    await user.click(cta);
    const drawer = await screen.findByRole('presentation');
    expect(within(drawer).getByText('Seu carrinho')).toBeInTheDocument();
    expect(within(drawer).getByText(/Subtotal/)).toBeInTheDocument();
    expect(within(drawer).getAllByText(/R\$\s15,90/).length).toBeGreaterThan(0);
    expect(within(drawer).getByRole('link', { name: 'Finalizar compra' })).toHaveAttribute('href', '/checkout');
  });

  it('409 insufficient_stock: mostra o disponível e desabilita o CTA', async () => {
    server.use(
      http.post('*/api/v1/cart/items', () =>
        HttpResponse.json(
          { message: 'Estoque insuficiente.', code: 'insufficient_stock', items: [{ cart_item_id: null, variant_id: 1, sku: 'VIN-BR-122-BR', product_name: 'Vinil Adesivo Branco', requested_quantity: 5, available_quantity: 3 }] },
          { status: 409 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderWithProviders(<Page />, { route, path });
    await screen.findByRole('heading', { level: 1, name: 'Vinil Adesivo Branco' });
    const input = screen.getByRole('spinbutton', { name: /Quantidade \(metros\)/ });
    await user.clear(input);
    await user.type(input, '5');
    const cta = screen.getAllByRole('button', { name: /Adicionar ao carrinho/ })[0];
    await waitFor(() => expect(cta).toBeEnabled());
    await user.click(cta);
    expect(await screen.findByText(/Estoque insuficiente\. Disponível: 3\sm\./)).toBeInTheDocument();
    expect(screen.getByText(/Disponível: 3\sm\. Ajuste a quantidade\./)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /Quantidade indisponível/ })[0]).toBeDisabled();
  });

  it('produto inexistente → 404 na mesma URL', async () => {
    renderWithProviders(<Page />, { route: '/vinis/nao-existe', path });
    expect(await screen.findByRole('heading', { name: 'Página não encontrada' })).toBeInTheDocument();
    expect(screen.getByText('Este produto não está mais disponível.')).toBeInTheDocument();
  });
});
