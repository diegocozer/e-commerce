import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import { loginAs, renderApp } from '@/test/utils';
import { emptyProduct, productSchema } from '../schemas/product';

async function chooseUnit(label: string) {
  await userEvent.click(screen.getByRole('combobox', { name: /Unidade de venda/ }));
  await userEvent.click(await screen.findByRole('option', { name: label }));
}

describe('Formulário de produto — campos condicionais por unidade', () => {
  it('mostra campos de m² só para SQUARE_METER e alterna largura fixa/variável', async () => {
    loginAs('super-admin');
    renderApp('/produtos/novo');
    await screen.findByRole('heading', { name: 'Novo produto', level: 1 });
    expect(screen.queryByTestId('square-fields')).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/Largura do material/)).not.toBeInTheDocument();

    await chooseUnit('Metro quadrado');
    expect(screen.getByTestId('square-fields')).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: /^Largura\s*\*?$/ })).toBeInTheDocument();
    expect(screen.getByLabelText(/Altura mínima/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Mínimo de peças/)).toBeInTheDocument();
    await userEvent.click(screen.getByLabelText('Largura variável'));
    expect(screen.getByLabelText(/Largura mínima/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Largura máxima/)).toBeInTheDocument();

    await chooseUnit('Metro linear');
    expect(screen.queryByTestId('square-fields')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Largura do material/)).toBeInTheDocument();

    await chooseUnit('Caixa');
    expect(screen.queryByLabelText(/Largura do material/)).not.toBeInTheDocument();
    expect(screen.getByText(/unidades por caixa/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /Mais campos da variante 1/ }));
    expect(await screen.findByLabelText(/Unidades por caixa/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Conteúdo do rolo/)).not.toBeInTheDocument();
  });

  it('valida exigências da unidade (m² exige largura/altura; inteiros para UNIT)', () => {
    const base = { ...emptyProduct(), name: 'Lona', primary_category_id: 2 };
    base.variants[0] = { ...base.variants[0], sku: 'LON-1', price_cents: 3000, weight_grams: 440 };
    const sq = productSchema.safeParse({ ...base, sale_unit: 'SQUARE_METER' });
    const paths = (sq.error?.issues ?? []).map((i) => i.path.join('.'));
    expect(paths).toEqual(expect.arrayContaining(['fixed_width_m', 'min_height_m', 'max_height_m']));
    const unit = productSchema.safeParse({ ...base, sale_unit: 'UNIT', quantity_step: '0.5', min_quantity: '1' });
    expect((unit.error?.issues ?? []).find((i) => i.path.join('.') === 'quantity_step')?.message).toBe('Use um número inteiro.');
    expect(productSchema.safeParse({ ...base, sale_unit: 'LINEAR_METER', quantity_step: '0.1', min_quantity: '1' }).success).toBe(true);
  });

  it('cria produto m² enviando metros e centavos e mapeia 422 de variante', async () => {
    loginAs('super-admin');
    const bodies: Record<string, unknown>[] = [];
    server.use(
      http.post('*/api/v1/admin/products', async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json({ message: 'x', errors: { 'variants.0.sku': ['SKU já usado em outro produto.'] } }, { status: 422 });
      }),
    );
    renderApp('/produtos/novo');
    await screen.findByRole('heading', { name: 'Novo produto', level: 1 });
    await userEvent.type(screen.getAllByLabelText(/^Nome/)[0], 'Lona Backlight 3,20 m');
    await userEvent.click(screen.getByRole('combobox', { name: /Categoria principal/ }));
    await userEvent.click(await screen.findByRole('option', { name: 'Lonas' }));
    await chooseUnit('Metro quadrado');
    await userEvent.type(screen.getByRole('textbox', { name: /^Largura\s*\*?$/ }), '3,2');
    await userEvent.type(screen.getByLabelText(/Altura mínima/), '0,5');
    await userEvent.type(screen.getByLabelText(/Altura máxima/), '50');
    const variants = screen.getByRole('table', { name: 'Variantes' });
    await userEvent.type(within(variants).getByLabelText(/^SKU/), 'lon-bl-320');
    await userEvent.type(within(variants).getByLabelText(/^Preço/), '45,90');
    await userEvent.type(within(variants).getByLabelText(/^Peso/), '520');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar produto' }));
    await waitFor(() => expect(bodies).toHaveLength(1));
    const body = bodies[0] as { sale_unit: string; fixed_width_m: number; min_height_m: number; max_height_m: number; min_width_m: null; variants: Record<string, unknown>[] };
    expect(body.sale_unit).toBe('SQUARE_METER');
    expect(body.fixed_width_m).toBe(3.2);
    expect(body.min_height_m).toBe(0.5);
    expect(body.max_height_m).toBe(50);
    expect(body.min_width_m).toBeNull();
    expect(body.variants[0]).toMatchObject({ sku: 'LON-BL-320', price_cents: 4590, weight_grams: 520 });
    expect(await screen.findAllByText('SKU já usado em outro produto.')).not.toHaveLength(0);
  });
});
