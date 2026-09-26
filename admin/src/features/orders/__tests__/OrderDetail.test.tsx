import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { makeOrder } from '@/mocks/seed';
import { server } from '@/mocks/server';
import { loginAs, renderApp } from '@/test/utils';
import type { AdminOrder } from '@/shared/api/types';

function useCarrierOrder(): AdminOrder {
  const o = makeOrder(10, 'processing');
  o.shipping = { ...o.shipping, method_name: 'Transportadora Rápida', method_type: 'carrier' };
  db.orders.push(o);
  return o;
}

describe('Detalhe do pedido', () => {
  it('mostra itens com configuração m² e instrução de separação', async () => {
    loginAs('super-admin');
    renderApp('/pedidos/1');
    expect(await screen.findByRole('heading', { name: 'Pedido CV-000001', level: 1 })).toBeInTheDocument();
    expect(screen.getByTestId('item-configuration').textContent?.replace(/ /g, ' ')).toBe('1,20 m × 2,50 m × 1 peça = 3,00 m²');
    expect(screen.getByText('Cortar: 1 peça de 1,20 × 2,50 m')).toBeInTheDocument();
    expect(screen.getByText('Separar: 5 m')).toBeInTheDocument();
  });

  it('exibe somente as transições devolvidas pela API', async () => {
    loginAs('super-admin');
    renderApp('/pedidos/1'); // paid → só "Marcar em separação"
    await screen.findByRole('heading', { name: 'Pedido CV-000001', level: 1 });
    expect(screen.getByRole('button', { name: 'Marcar em separação' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como enviado' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Marcar como entregue' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancelar pedido' })).toBeInTheDocument();
  });

  it('não mostra ações quando a API não permite (allowed_transitions vazio, can_cancel false)', async () => {
    loginAs(['orders.view', 'dashboard.view']);
    renderApp('/pedidos/1');
    await screen.findByRole('heading', { name: 'Pedido CV-000001', level: 1 });
    expect(screen.queryByRole('button', { name: /Marcar/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancelar pedido' })).not.toBeInTheDocument();
  });

  it('marca como enviado exigindo código de rastreio (transportadora) e posta em /transitions', async () => {
    loginAs('super-admin');
    const order = useCarrierOrder();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post('*/api/v1/admin/orders/:id/transitions', async ({ request, params }) => {
        body = (await request.json()) as Record<string, unknown>;
        const updated = { ...db.orders.find((o) => o.id === Number(params.id))!, status: 'shipped' as const, status_label: 'Enviado', allowed_transitions: [{ to_status: 'delivered' as const, label: 'Marcar como entregue', required_fields: [], optional_fields: ['note' as const] }], can_cancel: false };
        updated.shipping = { ...updated.shipping, tracking_code: String(body.tracking_code) };
        return HttpResponse.json({ data: updated });
      }),
    );
    renderApp(`/pedidos/${order.id}`);
    await userEvent.click(await screen.findByRole('button', { name: 'Marcar como enviado' }));
    const dialog = await screen.findByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: 'Marcar como enviado' });

    await userEvent.click(submit);
    expect(await within(dialog).findByText('Informe o código de rastreio.')).toBeInTheDocument();
    expect(body).toBeNull();

    await userEvent.type(within(dialog).getByLabelText(/Código de rastreio/), 'BR123456789');
    await userEvent.type(within(dialog).getByLabelText(/URL de rastreio/), 'https://rastreio.test/BR123456789');
    await userEvent.click(submit);

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ to_status: 'shipped', tracking_code: 'BR123456789', tracking_url: 'https://rastreio.test/BR123456789' });
    expect(await screen.findByText('Pedido marcado como enviado')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Marcar como entregue' })).toBeInTheDocument();
  });

  it('retirada exige nome e documento e conferência do número', async () => {
    loginAs(['orders.view', 'orders.pickup']);
    renderApp('/pedidos/4');
    await userEvent.click(await screen.findByRole('button', { name: 'Marcar como retirado' }));
    const dialog = await screen.findByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: 'Marcar como retirado' });
    expect(submit).toBeDisabled();
    await userEvent.type(within(dialog).getByLabelText(/Confira o número do pedido/), 'CV-000004');
    await userEvent.click(submit);
    expect(await within(dialog).findByText(/Informe o nome de quem retirou/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Informe o documento/)).toBeInTheDocument();
  });

  it('409 invalid_status_transition avisa e recarrega', async () => {
    loginAs('super-admin');
    server.use(http.post('*/api/v1/admin/orders/:id/transitions', () => HttpResponse.json({ message: 'Transição inválida', code: 'invalid_status_transition', allowed_transitions: [] }, { status: 409 })));
    renderApp('/pedidos/1');
    await userEvent.click(await screen.findByRole('button', { name: 'Marcar em separação' }));
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Marcar em separação' }));
    expect(await screen.findByText(/atualizado por outra pessoa/)).toBeInTheDocument();
  });

  it('cancelar pedido pago exige motivo e confirmação de estorno', async () => {
    loginAs('super-admin');
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post('*/api/v1/admin/orders/:id/cancel', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: { ...db.orders[0], status: 'cancelled', allowed_transitions: [], can_cancel: false } });
      }),
    );
    renderApp('/pedidos/1');
    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar pedido' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/será estornado via PIX/)).toBeInTheDocument();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar e estornar' }));
    expect(await within(dialog).findByText('Selecione o motivo.')).toBeInTheDocument();
    expect(within(dialog).getByText('Confirme o estorno para continuar.')).toBeInTheDocument();

    await userEvent.click(within(dialog).getByLabelText(/Motivo/));
    await userEvent.click(await screen.findByRole('option', { name: 'Falta de estoque' }));
    await userEvent.click(within(dialog).getByLabelText('Confirmo o estorno'));
    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar e estornar' }));
    await waitFor(() => expect(body).toEqual({ reason: 'Falta de estoque', confirm_refund: true }));
  });
});
