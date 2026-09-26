import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import type { InventoryItem } from '@/shared/api/types';
import { loginAs, renderWithProviders } from '@/test/utils';
import { AdjustDialog, EntryDialog } from '../components/StockDialogs';

const item: InventoryItem = {
  variant_id: 10, sku: 'VIN-BR-122-BR', variant_name: 'Brilho', product: { id: 1, name: 'Vinil Adesivo Branco 1,22 m', slug: 'vinil', is_active: true },
  sale_unit: 'LINEAR_METER', stock_unit_abbr: 'm', on_hand: 450, reserved: 30, available: 420, low_stock_threshold: 100, low_stock_threshold_override: null,
  is_low_stock: false, low_stock_alerted_at: null, updated_at: null,
};

async function pick(dialog: HTMLElement, label: RegExp, option: string) {
  await userEvent.click(within(dialog).getByLabelText(label));
  await userEvent.click(await screen.findByRole('option', { name: option }));
}

describe('Ajuste de estoque', () => {
  it('exige motivo e não envia sem ele', async () => {
    loginAs('super-admin');
    let called = false;
    server.use(http.post('*/api/v1/admin/inventory/:id/adjustments', () => { called = true; return HttpResponse.json({}, { status: 201 }); }));
    renderWithProviders(<AdjustDialog item={item} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText(/Novo valor em mãos/), '438');
    expect(within(dialog).getByRole('status').textContent?.replace(/ /g, ' ')).toBe('Diferença: −12 m');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar ajuste' }));
    expect(await within(dialog).findByText('Selecione o motivo.')).toBeInTheDocument();
    expect(called).toBe(false);
  });

  it('"Outro" exige descrição; bloqueia valor abaixo do reservado; envia motivo composto', async () => {
    loginAs('super-admin');
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post('*/api/v1/admin/inventory/:id/adjustments', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: { movement: {}, inventory: item } }, { status: 201 });
      }),
    );
    renderWithProviders(<AdjustDialog item={item} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText(/Novo valor em mãos/), '20');
    await pick(dialog, /^Motivo/, 'Outro');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar ajuste' }));
    expect(await within(dialog).findByText(/Não pode ser menor que o reservado \(30 m\)/)).toBeInTheDocument();
    expect(within(dialog).getByText('Descreva o motivo (mín. 3 caracteres).')).toBeInTheDocument();

    await userEvent.clear(within(dialog).getByLabelText(/Novo valor em mãos/));
    await userEvent.type(within(dialog).getByLabelText(/Novo valor em mãos/), '440,5');
    await pick(dialog, /^Motivo/, 'Inventário/contagem');
    await userEvent.type(within(dialog).getByLabelText(/Observação/), 'Contagem mensal');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar ajuste' }));
    await waitFor(() => expect(body).toEqual({ new_on_hand: 440.5, reason: 'Inventário/contagem — Contagem mensal', expected_on_hand: 450 }));
  });

  it('diferença > 10% exige confirmação', async () => {
    loginAs('super-admin');
    renderWithProviders(<AdjustDialog item={item} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText(/Novo valor em mãos/), '100');
    expect(within(dialog).getByText(/acima de 10%/)).toBeInTheDocument();
    await pick(dialog, /^Motivo/, 'Avaria/perda');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar ajuste' }));
    expect(await within(dialog).findByText('Confirme para continuar.')).toBeInTheDocument();
  });

  it('entrada exige quantidade e motivo; NF compõe o motivo', async () => {
    loginAs('super-admin');
    let body: Record<string, unknown> | null = null;
    server.use(http.post('*/api/v1/admin/inventory/:id/entries', async ({ request }) => { body = (await request.json()) as Record<string, unknown>; return HttpResponse.json({ data: { movement: {}, inventory: item } }, { status: 201 }); }));
    renderWithProviders(<EntryDialog item={item} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar entrada' }));
    expect(await within(dialog).findByText('Informe uma quantidade maior que zero.')).toBeInTheDocument();
    expect(within(dialog).getByText('Selecione o motivo.')).toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText(/Quantidade/), '50');
    await pick(dialog, /^Motivo/, 'Compra de fornecedor');
    await userEvent.type(within(dialog).getByLabelText(/Observação \/ NF/), 'NF 4521');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar entrada' }));
    await waitFor(() => expect(body).toEqual({ quantity: 50, reason: 'Compra de fornecedor — NF 4521' }));
  });
});
