import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { findSeed, productDetail } from '@/mocks/data';
import { server } from '@/mocks/server';
import { renderWithProviders } from '@/test/utils';
import { QuantityConfigurator, type ConfiguratorState } from './QuantityConfigurator';

const vinil = productDetail(findSeed('vinil-adesivo-branco-122m')!);
const lona = productDetail(findSeed('lona-frontlight-440g')!);

describe('QuantityConfigurator', () => {
  it('LINEAR_METER: 5 m × R$ 15,90 = R$ 79,50 e usa o valor do servidor', async () => {
    const user = userEvent.setup();
    const states: ConfiguratorState[] = [];
    let previewBody: unknown = null;
    server.events.on('request:start', async ({ request }) => {
      if (request.url.includes('price-preview')) previewBody = await request.clone().json();
    });
    renderWithProviders(<QuantityConfigurator productSlug={vinil.slug} productName={vinil.name} variant={vinil.variants[0]} onStateChange={(s) => states.push(s)} />);
    const input = screen.getByRole('spinbutton', { name: /Quantidade \(metros\)/ });
    await user.clear(input);
    await user.type(input, '5');
    // prévia local imediata
    expect(screen.getByText(/5\sm × R\$\s15,90\s\/m = R\$\s79,50/)).toBeInTheDocument();
    expect(screen.getByText(/Total: R\$\s79,50/)).toBeInTheDocument();
    // resposta do servidor (debounce 300 ms) passa a ser a verdade
    await waitFor(() => expect(states.at(-1)?.preview?.line_total_cents).toBe(7950));
    expect(previewBody).toEqual({ variant_id: 1, quantity: 5 });
    expect(screen.getByTestId('configurator-total')).toHaveAttribute('aria-busy', 'false');
    expect(screen.getByText(/Peso aprox\.: 1,3\skg/)).toBeInTheDocument();
    server.events.removeAllListeners();
  });

  it('LINEAR_METER: 5,05 mostra erro de passo com sugestões e bloqueia o CTA', async () => {
    const user = userEvent.setup();
    const states: ConfiguratorState[] = [];
    renderWithProviders(<QuantityConfigurator productSlug={vinil.slug} productName={vinil.name} variant={vinil.variants[0]} onStateChange={(s) => states.push(s)} />);
    const input = screen.getByRole('spinbutton', { name: /Quantidade \(metros\)/ });
    await user.clear(input);
    await user.type(input, '5,05');
    expect(screen.getByText(/Use múltiplos de 0,10\sm\. Sugestões: 5,00\sm ou 5,10\sm\./)).toBeInTheDocument();
    await waitFor(() => expect(states.at(-1)?.blockedReason).toBe('Revise a quantidade'));
    await user.click(screen.getByRole('button', { name: 'Usar 5,10' }));
    expect(input).toHaveValue('5,10');
    expect(screen.getByText(/Total: R\$\s81,09/)).toBeInTheDocument();
  });

  it('stepper: + soma o passo e respeita o máximo', async () => {
    const user = userEvent.setup();
    renderWithProviders(<QuantityConfigurator productSlug={vinil.slug} productName={vinil.name} variant={vinil.variants[0]} />);
    const input = screen.getByRole('spinbutton', { name: /Quantidade \(metros\)/ });
    expect(screen.getByRole('button', { name: /Diminuir quantidade/ })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: /Aumentar quantidade de Vinil Adesivo Branco/ }));
    expect(input).toHaveValue('1,1');
    await user.clear(input);
    await user.type(input, '50');
    expect(screen.getByRole('button', { name: /Aumentar quantidade/ })).toBeDisabled();
  });

  it('SQUARE_METER: 1,20 × 2,50 × 1 → Área 3,00 m² e R$ 90,00', async () => {
    const user = userEvent.setup();
    renderWithProviders(<QuantityConfigurator productSlug={lona.slug} productName={lona.name} variant={lona.variants[0]} />);
    expect(screen.getByText(/Área: —/)).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Largura \(m\)/), '1,20');
    await user.type(screen.getByLabelText(/Altura \(m\)/), '2,50');
    expect(screen.getByText(/Área: 3,00\sm²/)).toBeInTheDocument();
    expect(screen.getByText(/3,00\sm² × R\$\s30,00\s\/m² = R\$\s90,00/)).toBeInTheDocument();
    expect(screen.getByText(/Total: R\$\s90,00/)).toBeInTheDocument();
  });

  it('SQUARE_METER: abaixo da área mínima mostra o aviso e cobra o mínimo', async () => {
    const user = userEvent.setup();
    renderWithProviders(<QuantityConfigurator productSlug={lona.slug} productName={lona.name} variant={lona.variants[0]} />);
    await user.type(screen.getByLabelText(/Largura \(m\)/), '0,40');
    await user.type(screen.getByLabelText(/Altura \(m\)/), '0,50');
    expect(screen.getByText(/Área: 0,20\sm²/)).toBeInTheDocument();
    expect(screen.getByText(/Cobramos a área mínima de 1,00\sm²/)).toBeInTheDocument();
    expect(screen.getByText(/1,00\sm² \(mínimo\) × R\$\s30,00/)).toBeInTheDocument();
    expect(screen.getByText(/Total: R\$\s30,00/)).toBeInTheDocument();
  });

  it('exibe o erro 422 do servidor (ex.: inverter medidas) no campo', async () => {
    server.use(
      http.post('*/api/v1/products/:slug/price-preview', () =>
        HttpResponse.json({ message: 'Altura acima do permitido.', errors: { height_m: ['Altura acima do estoque de bobina.'] } }, { status: 422 }),
      ),
    );
    const user = userEvent.setup();
    renderWithProviders(<QuantityConfigurator productSlug={lona.slug} productName={lona.name} variant={lona.variants[0]} />);
    await user.type(screen.getByLabelText(/Largura \(m\)/), '1,20');
    await user.type(screen.getByLabelText(/Altura \(m\)/), '2,50');
    expect(await screen.findByText('Altura acima do estoque de bobina.')).toBeInTheDocument();
  });
});
