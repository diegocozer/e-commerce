import { http } from 'msw';
import type { SimulationResult } from '@/shared/api/extraTypes';
import type { Carrier, ShippingMethod, ShippingRule, ShippingZone, UF } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { db, nextId } from '../db';
import { B, conflict, invalid, noContent, notFound, now, ok } from '../util';

const CITIES = [
  { ibge_code: '4202404', name: 'Blumenau', state: 'SC' as UF },
  { ibge_code: '4205902', name: 'Gaspar', state: 'SC' as UF },
  { ibge_code: '4213203', name: 'Pomerode', state: 'SC' as UF },
  { ibge_code: '4207502', name: 'Indaial', state: 'SC' as UF },
  { ibge_code: '4209102', name: 'Joinville', state: 'SC' as UF },
];

function summarize(r: ShippingRule): string {
  const zone = db.zones.find((z) => z.id === r.zone_id)?.name ?? 'Todas as zonas';
  const parts: string[] = [zone];
  const kg = (g: number) => `${g / 1000} kg`.replace('.', ',');
  if (r.min_weight_grams !== null && r.max_weight_grams !== null) parts.push(`${kg(r.min_weight_grams)}–${kg(r.max_weight_grams)}`);
  else if (r.max_weight_grams !== null) parts.push(`≤ ${kg(r.max_weight_grams)}`);
  else if (r.min_weight_grams !== null) parts.push(`≥ ${kg(r.min_weight_grams)}`);
  if (r.min_subtotal_cents !== null) parts.push(`Subtotal ≥ ${formatBRL(r.min_subtotal_cents)}`);
  const price = r.price_type === 'free' ? 'Grátis' : formatBRL(r.price_cents);
  return `${parts.join(' · ')} → ${price}`;
}

function zoneBody(b: { name: string; description?: string | null; is_active?: boolean; postal_ranges?: { start_postal_code: string; end_postal_code: string }[]; cities?: { city_ibge_code: string }[]; states?: UF[] }, id: number): ShippingZone {
  return {
    id, name: b.name, description: b.description ?? null, is_active: b.is_active ?? true,
    postal_ranges: (b.postal_ranges ?? []).map((r, i) => ({ id: i + 1, start_postal_code: r.start_postal_code.replace(/\D/g, ''), end_postal_code: r.end_postal_code.replace(/\D/g, '') })),
    cities: (b.cities ?? []).map((c, i) => {
      const city = CITIES.find((x) => x.ibge_code === c.city_ibge_code);
      return { id: i + 1, city_ibge_code: c.city_ibge_code, city_name: city?.name ?? c.city_ibge_code, state: city?.state ?? 'SC' };
    }),
    states: b.states ?? [], rules_count: db.rules.filter((r) => r.zone_id === id).length, created_at: now(), updated_at: now(),
  };
}

function simulate(body: { postal_code: string; subtotal_cents?: number; logistics_override?: { total_weight_grams: number } }): SimulationResult {
  const cep = body.postal_code.replace(/\D/g, '');
  const weight = body.logistics_override?.total_weight_grams ?? 2570;
  const subtotal = body.subtotal_cents ?? 16950;
  const inBlumenau = cep >= '89000000' && cep <= '89099999';
  const zones = inBlumenau ? [{ zone_id: 1, name: 'Blumenau', matched_by: 'postal_range:89000000-89099999', specificity: 'postal_range' }] : [];
  const rules = db.rules.filter((r) => r.method_id === 2).sort((a, b) => a.priority - b.priority);
  let winner: ShippingRule | null = null;
  const traces = rules.map((r) => {
    if (!zones.some((z) => z.zone_id === r.zone_id)) return { rule_id: r.id, priority: r.priority, result: 'zone_not_matched' };
    if (winner) return { rule_id: r.id, priority: r.priority, result: 'not_evaluated' };
    const reasons: { code: string; detail: string }[] = [];
    if (r.max_weight_grams !== null && weight > r.max_weight_grams) reasons.push({ code: 'weight_above_max', detail: `${weight} g > ${r.max_weight_grams} g` });
    if (r.min_weight_grams !== null && weight < r.min_weight_grams) reasons.push({ code: 'weight_below_min', detail: `${weight} g < ${r.min_weight_grams} g` });
    if (r.min_subtotal_cents !== null && subtotal < r.min_subtotal_cents) reasons.push({ code: 'subtotal_below_min', detail: `${formatBRL(subtotal)} < ${formatBRL(r.min_subtotal_cents)}` });
    if (reasons.length) return { rule_id: r.id, priority: r.priority, result: 'rejected', reasons };
    winner = r;
    return { rule_id: r.id, priority: r.priority, result: 'matched' };
  });
  const w = winner as ShippingRule | null;
  const options: SimulationResult['options'] = [
    { option_id: '1:pickup', method_code: 'retirada', method_type: 'pickup', name: 'Retirada na loja', description: null, price_cents: 0, original_price_cents: 0, is_free: true, free_reason: 'rule', delivery_days_min: 0, delivery_days_max: 1, delivery_label: 'Disponível em 1 dia útil após o pagamento', carrier: null, pickup_address: null },
  ];
  if (w) options.push({ option_id: `2:${w.id}`, method_code: 'entrega-propria', method_type: 'own_delivery', name: 'Entrega própria', description: null, price_cents: w.price_type === 'free' ? 0 : w.price_cents, original_price_cents: w.price_cents, is_free: w.price_type === 'free', free_reason: w.price_type === 'free' ? 'rule' : null, delivery_days_min: 1, delivery_days_max: 1, delivery_label: '1 dia útil', carrier: null, pickup_address: null });
  options.push({ option_id: '3:EXP', method_code: 'rapida-expresso', method_type: 'carrier', name: 'Transportadora Rápida', description: null, price_cents: 3890, original_price_cents: 3890, is_free: false, free_reason: null, delivery_days_min: 3, delivery_days_max: 5, delivery_label: '3 a 5 dias úteis', carrier: { code: 'rapida', name: 'Transportadora Rápida', service_code: 'EXP', service_name: 'Expresso' }, pickup_address: null });
  return {
    destination: { postal_code: cep, city: inBlumenau ? 'Blumenau' : null, city_ibge_code: inBlumenau ? '4202404' : null, state: inBlumenau ? 'SC' : null, resolved: inBlumenau, state_source: 'lookup' },
    logistics: { total_weight_grams: weight, total_volume_cm3: 12500, largest_dimension_cm: 125, volumes_count: 2, missing_data: false },
    zones_matched: zones,
    methods: [
      { method_id: 1, code: 'retirada', name: 'Retirada na loja', status: 'option' },
      { method_id: 2, code: 'entrega-propria', name: 'Entrega própria', status: w ? 'option' : 'unavailable', reason: w ? undefined : inBlumenau ? 'no_rule_matched' : 'out_of_coverage', coverage: { covered: inBlumenau, via_zones: zones.map((z) => z.zone_id) }, effective_weight_grams: weight, weight_basis: 'real', rules: traces, winner_rule_id: w?.id ?? null, price_breakdown: w ? { price_type: w.price_type, price_cents: w.price_cents, final_cents: w.price_type === 'free' ? 0 : w.price_cents } : undefined, warnings: [] },
      { method_id: 3, code: 'rapida-expresso', name: 'Transportadora Rápida', status: 'option', duration_ms: 1200 },
    ],
    options,
    unavailable: w ? [] : [{ method_id: 2, method_code: 'entrega-propria', reason: inBlumenau ? 'no_rule_matched' : 'out_of_coverage', detail: null }],
  };
}

export const shippingHandlers = [
  http.get(`${B}/admin/shipping/carriers/drivers`, () => ok([{ driver: 'fake', name: 'Fake (testes)', settings_schema: { service_codes: 'string' } }, { driver: 'melhor_envio', name: 'Melhor Envio', settings_schema: { sandbox: 'boolean' } }])),
  http.get(`${B}/admin/shipping/carriers`, () => ok(db.carriers)),
  http.post(`${B}/admin/shipping/carriers`, async ({ request }) => {
    const b = (await request.json()) as Partial<Carrier> & { credentials?: unknown };
    const c: Carrier = { id: nextId(), name: b.name!, code: b.code!, driver: b.driver!, settings: b.settings ?? {}, has_credentials: !!b.credentials, is_active: b.is_active ?? true, methods_count: 0, created_at: now(), updated_at: now() };
    db.carriers.push(c);
    return ok(c, 201);
  }),
  http.patch(`${B}/admin/shipping/carriers/:id`, async ({ params, request }) => {
    const c = db.carriers.find((x) => x.id === Number(params.id));
    if (!c) return notFound();
    const { credentials, ...rest } = (await request.json()) as Partial<Carrier> & { credentials?: unknown };
    Object.assign(c, rest, credentials !== undefined ? { has_credentials: credentials !== null } : {});
    return ok(c);
  }),
  http.delete(`${B}/admin/shipping/carriers/:id`, ({ params }) =>
    db.methods.some((m) => m.carrier_id === Number(params.id)) ? conflict('resource_in_use', 'Transportadora usada por métodos.', { blockers: db.methods.filter((m) => m.carrier_id === Number(params.id)).map((m) => ({ type: 'shipping_method', id: m.id, label: m.name })) }) : noContent(),
  ),
  http.post(`${B}/admin/shipping/carriers/:id/test`, () => ok({ ok: true, duration_ms: 842, message: 'Conexão OK' })),
  http.get(`${B}/admin/shipping/methods`, () => ok([...db.methods].sort((a, b) => a.position - b.position))),
  http.post(`${B}/admin/shipping/methods`, async ({ request }) => {
    const b = (await request.json()) as Partial<ShippingMethod>;
    const m = { ...(b as ShippingMethod), id: nextId(), rules_count: 0, position: db.methods.length, created_at: now(), updated_at: now(), deleted_at: null, pickup: b.pickup ?? null };
    db.methods.push(m);
    return ok(m, 201);
  }),
  http.patch(`${B}/admin/shipping/methods/:id`, async ({ params, request }) => {
    const m = db.methods.find((x) => x.id === Number(params.id));
    if (!m) return notFound();
    Object.assign(m, await request.json(), { updated_at: now() });
    return ok(m);
  }),
  http.delete(`${B}/admin/shipping/methods/:id`, ({ params }) => {
    db.methods = db.methods.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  http.put(`${B}/admin/shipping/methods/reorder`, async ({ request }) => {
    const { ids } = (await request.json()) as { ids: number[] };
    ids.forEach((id, i) => {
      const m = db.methods.find((x) => x.id === id);
      if (m) m.position = i;
    });
    return noContent();
  }),
  http.get(`${B}/admin/shipping/cities`, ({ request }) => {
    const url = new URL(request.url);
    const s = (url.searchParams.get('search') ?? '').toLowerCase();
    return ok(CITIES.filter((c) => c.name.toLowerCase().includes(s)));
  }),
  http.get(`${B}/admin/shipping/zones`, () => ok(db.zones)),
  http.get(`${B}/admin/shipping/zones/:id`, ({ params }) => {
    const z = db.zones.find((x) => x.id === Number(params.id));
    return z ? ok(z) : notFound();
  }),
  http.post(`${B}/admin/shipping/zones`, async ({ request }) => {
    const b = (await request.json()) as Parameters<typeof zoneBody>[0];
    if (!b.name) return invalid({ name: ['Informe o nome.'] });
    const z = zoneBody(b, nextId());
    db.zones.push(z);
    return Response.json({ data: z, warnings: [] }, { status: 201 });
  }),
  http.patch(`${B}/admin/shipping/zones/:id`, async ({ params, request }) => {
    const idx = db.zones.findIndex((x) => x.id === Number(params.id));
    if (idx < 0) return notFound();
    const b = (await request.json()) as Parameters<typeof zoneBody>[0];
    const z = zoneBody({ ...db.zones[idx], ...b, cities: b.cities ?? db.zones[idx].cities }, db.zones[idx].id);
    db.zones[idx] = z;
    const overlaps = z.postal_ranges.some((r) => r.start_postal_code < '89099999' && r.end_postal_code > '89000000') && z.id !== 1;
    return Response.json({ data: z, warnings: overlaps ? ['A faixa sobrepõe a faixa 89000-000–89099-999 da zona Blumenau.'] : [] });
  }),
  http.delete(`${B}/admin/shipping/zones/:id`, ({ params }) => {
    const used = db.rules.filter((r) => r.zone_id === Number(params.id));
    if (used.length) return conflict('resource_in_use', 'A zona é usada por regras.', { blockers: used.map((r) => ({ type: 'shipping_rule', id: r.id, label: r.name })) });
    db.zones = db.zones.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  http.post(`${B}/admin/shipping/zones/:id/test`, async ({ params, request }) => {
    const z = db.zones.find((x) => x.id === Number(params.id));
    const { postal_code } = (await request.json()) as { postal_code: string };
    const cep = postal_code.replace(/\D/g, '');
    const range = z?.postal_ranges.find((r) => cep >= r.start_postal_code && cep <= r.end_postal_code);
    return ok({ matches: !!range, matched_by: range ? `postal_range:${range.start_postal_code}-${range.end_postal_code}` : null, destination: { city: range ? 'Blumenau' : null, state: range ? 'SC' : null, city_ibge_code: range ? '4202404' : null, resolved: true } });
  }),
  http.get(`${B}/admin/shipping/rules`, ({ request }) => {
    const sp = new URL(request.url).searchParams;
    let rows = db.rules.filter((r) => !r.deleted_at);
    if (sp.get('method_id')) rows = rows.filter((r) => r.method_id === Number(sp.get('method_id')));
    if (sp.get('zone_id')) rows = rows.filter((r) => (sp.get('zone_id') === 'null' ? r.zone_id === null : r.zone_id === Number(sp.get('zone_id'))));
    return ok([...rows].sort((a, b) => a.method_id - b.method_id || (a.zone_id ?? 0) - (b.zone_id ?? 0) || a.priority - b.priority || a.id - b.id));
  }),
  http.post(`${B}/admin/shipping/rules/reorder`, async ({ request }) => {
    const b = (await request.json()) as { method_id: number; zone_id: number | null; ids: number[] };
    b.ids.forEach((id, i) => {
      const r = db.rules.find((x) => x.id === id);
      if (r) r.priority = (i + 1) * 10;
    });
    return ok(db.rules.filter((r) => r.method_id === b.method_id && r.zone_id === b.zone_id).sort((a, c) => a.priority - c.priority));
  }),
  http.post(`${B}/admin/shipping/rules/:id/duplicate`, ({ params }) => {
    const r = db.rules.find((x) => x.id === Number(params.id));
    if (!r) return notFound();
    const copy = { ...r, id: nextId(), name: `${r.name} (cópia)`, is_active: false, priority: r.priority + 1 };
    db.rules.push(copy);
    return ok(copy, 201);
  }),
  http.post(`${B}/admin/shipping/rules`, async ({ request }) => {
    const b = (await request.json()) as ShippingRule;
    const m = db.methods.find((x) => x.id === b.method_id);
    if (!m || !['own_delivery', 'table_rate'].includes(m.type)) return invalid({ method_id: ['Regras só se aplicam a métodos de entrega própria ou frete por tabela.'] });
    const r: ShippingRule = { ...b, id: nextId(), summary: '', created_at: now(), updated_at: now(), deleted_at: null };
    r.summary = summarize(r);
    db.rules.push(r);
    const tie = db.rules.some((x) => x.id !== r.id && x.method_id === r.method_id && x.zone_id === r.zone_id && x.priority === r.priority);
    return Response.json({ data: r, warnings: tie ? ['tie_broken_by_id'] : [] }, { status: 201 });
  }),
  http.patch(`${B}/admin/shipping/rules/:id`, async ({ params, request }) => {
    const r = db.rules.find((x) => x.id === Number(params.id));
    if (!r) return notFound();
    Object.assign(r, await request.json(), { updated_at: now() });
    r.summary = summarize(r);
    return Response.json({ data: r, warnings: [] });
  }),
  http.delete(`${B}/admin/shipping/rules/:id`, ({ params }) => {
    db.rules = db.rules.filter((x) => x.id !== Number(params.id));
    return noContent();
  }),
  http.post(`${B}/admin/shipping/simulate`, async ({ request }) => {
    const b = (await request.json()) as Parameters<typeof simulate>[0];
    if (!b.postal_code || b.postal_code.replace(/\D/g, '').length !== 8) return invalid({ postal_code: ['Informe um CEP válido.'] });
    return ok(simulate(b));
  }),
];
