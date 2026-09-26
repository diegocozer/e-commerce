import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { seedMethods, seedZones } from '@/mocks/seed';
import { server } from '@/mocks/server';
import { loginAs, renderWithProviders } from '@/test/utils';
import { RuleDialog } from '../components/RuleDialog';
import { ruleConditionChips, ruleSchema, emptyRule } from '../schemas/rule';

const methods = seedMethods();
const zones = seedZones();

describe('Validação da regra de frete (schema)', () => {
  const schema = ruleSchema(methods);
  it('exige método de entrega própria/tabela, nome e prioridade', () => {
    const r = schema.safeParse({ ...emptyRule(1, null), name: '', priority: null, price_cents: 1000 });
    expect(r.success).toBe(false);
    const msgs = Object.fromEntries((r.error?.issues ?? []).map((i) => [i.path.join('.'), i.message]));
    expect(msgs.method_id).toMatch(/entrega própria ou frete por tabela/);
    expect(msgs.name).toBeDefined();
    expect(msgs.priority).toBeDefined();
  });
  it('máximo ≥ mínimo e campos por tipo de preço', () => {
    const r = schema.safeParse({ ...emptyRule(2, 1), name: 'X', min_weight_kg: '10', max_weight_kg: '5', price_type: 'per_kg', per_kg_cents: null });
    const paths = (r.error?.issues ?? []).map((i) => i.path.join('.'));
    expect(paths).toContain('max_weight_kg');
    expect(paths).toContain('per_kg_cents');
  });
  it('aceita regra válida', () => {
    expect(schema.safeParse({ ...emptyRule(2, 1), name: 'Blumenau até 10 kg', max_weight_kg: '10', price_cents: 2000 }).success).toBe(true);
  });
  it('chips em linguagem natural', () => {
    const base = { min_weight_grams: null, max_weight_grams: 10000, min_subtotal_cents: null, max_subtotal_cents: null, min_volume_cm3: null, max_volume_cm3: 500000, max_package_length_cm: null };
    expect(ruleConditionChips(base).map((c) => c.replace(/ /g, ' '))).toEqual(['≤ 10 kg', 'Volume ≤ 0,5 m³']);
    expect(ruleConditionChips({ ...base, max_weight_grams: 50000, min_weight_grams: 10000, max_volume_cm3: null, min_subtotal_cents: 50000 }).map((c) => c.replace(/ /g, ' '))).toEqual(['10–50 kg', 'Subtotal ≥ R$ 500,00']);
  });
});

describe('RuleDialog', () => {
  it('mostra erros de validação e não envia; depois envia gramas/centavos', async () => {
    loginAs('super-admin');
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post('*/api/v1/admin/shipping/rules', async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ data: { ...body, id: 99, summary: '' }, warnings: [] }, { status: 201 });
      }),
    );
    renderWithProviders(<RuleDialog rule={null} defaults={{ method_id: 2, zone_id: 1 }} methods={methods} zones={zones} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText('Peso ≥'), '10');
    await userEvent.type(within(dialog).getByLabelText('Peso ≤'), '5');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar regra' }));
    expect(await within(dialog).findByText('Informe o nome (até 150 caracteres).')).toBeInTheDocument();
    expect(within(dialog).getByText('O peso máximo deve ser ≥ mínimo.')).toBeInTheDocument();
    expect(within(dialog).getByText(/Informe o preço/)).toBeInTheDocument();
    expect(body).toBeNull();

    await userEvent.type(within(dialog).getByLabelText(/^Nome/), 'Blumenau até 10 kg');
    await userEvent.clear(within(dialog).getByLabelText('Peso ≥'));
    await userEvent.clear(within(dialog).getByLabelText('Peso ≤'));
    await userEvent.type(within(dialog).getByLabelText('Peso ≤'), '10');
    await userEvent.type(within(dialog).getByLabelText(/^Preço/), '20,00');
    expect(within(dialog).getByTestId('rule-preview').textContent?.replace(/ /g, ' ')).toContain('pedidos com ≤ 10 kg pagam R$ 20,00');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar regra' }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ method_id: 2, zone_id: 1, name: 'Blumenau até 10 kg', max_weight_grams: 10000, min_weight_grams: null, price_type: 'fixed', price_cents: 2000, per_kg_cents: 0 });
  });

  it('422 da API vai para o campo (max_weight_grams → Peso ≤)', async () => {
    loginAs('super-admin');
    server.use(http.post('*/api/v1/admin/shipping/rules', () => HttpResponse.json({ message: 'x', errors: { max_weight_grams: ['Peso máximo inválido pela API.'] } }, { status: 422 })));
    renderWithProviders(<RuleDialog rule={null} defaults={{ method_id: 2, zone_id: 1 }} methods={methods} zones={zones} onClose={() => {}} />);
    const dialog = await screen.findByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText(/^Nome/), 'Regra');
    await userEvent.type(within(dialog).getByLabelText(/^Preço/), '10');
    await userEvent.type(within(dialog).getByLabelText('Peso ≤'), '1');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Salvar regra' }));
    expect(await within(dialog).findByText('Peso máximo inválido pela API.')).toBeInTheDocument();
  });
});
