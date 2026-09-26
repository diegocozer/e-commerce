import { describe, expect, it } from 'vitest';
import type { SaleUnitRules } from '../api/types';
import { checkConfiguration, computeLocalPreview, stepMessage } from './configuration';
import { formatMilli, parseDecimal, toMilli } from './decimal';
import { checkStep, estimateWeightGrams, lineTotalCents, pieceAreaMilli, squareMeterArea } from './math';

const base: SaleUnitRules = {
  sale_unit: 'LINEAR_METER', input: 'decimal', min_quantity: 1, max_quantity: 50, quantity_step: 0.1,
  fixed_width_m: 1.22, min_width_m: null, max_width_m: null, min_height_m: null, max_height_m: null,
  min_billable_area_m2: null, dimension_decimals: 2, max_pieces: 1000,
};
const lona: SaleUnitRules = {
  ...base, sale_unit: 'SQUARE_METER', input: 'dimensions', min_quantity: 1, max_quantity: null, quantity_step: 1,
  fixed_width_m: null, min_width_m: 0.3, max_width_m: 3.2, min_height_m: 0.3, max_height_m: 50, min_billable_area_m2: 1,
};
const draft = (p: Partial<{ quantity: string; width: string; height: string; pieces: string }>) => ({ quantity: '', width: '', height: '', pieces: '1', ...p });

describe('decimal (string → milésimos, sem float)', () => {
  it('aceita vírgula e ponto', () => {
    expect(parseDecimal('5,5')).toEqual({ ok: true, value: '5.5', milli: 5500 });
    expect(parseDecimal('5.35')).toMatchObject({ ok: true, milli: 5350 });
    expect(parseDecimal('1.234,5')).toMatchObject({ ok: true, milli: 1234500 });
  });
  it('rejeita > 3 casas, negativos e notação científica', () => {
    expect(parseDecimal('5,1234')).toEqual({ ok: false, error: 'too_many_decimals' });
    expect(parseDecimal('-1')).toEqual({ ok: false, error: 'invalid' });
    expect(parseDecimal('1e3')).toEqual({ ok: false, error: 'invalid' });
    expect(parseDecimal('')).toEqual({ ok: false, error: 'empty' });
  });
  it('números da API → milésimos exatos', () => {
    expect(toMilli(0.1)).toBe(100);
    expect(toMilli(2.35)).toBe(2350);
    expect(formatMilli(5500)).toBe('5,5');
    expect(formatMilli(3000, 2, 2)).toBe('3,00');
  });
});

describe('math (espelho do backend, ADR-003/019)', () => {
  it('5 m × R$ 15,90 = 7950', () => {
    expect(lineTotalCents(1590, 5000)).toBe(7950);
  });
  it('5,35 m × R$ 15,90 = 8507 (round half up de 8506,5)', () => {
    expect(lineTotalCents(1590, 5350)).toBe(8507);
  });
  it('1,20 × 2,50 → 3,000 m² → 9000', () => {
    expect(pieceAreaMilli(1200, 2500)).toBe(3000);
    const a = squareMeterArea(1200, 2500, 1, 1000);
    expect(a).toEqual({ pieceAreaMilli: 3000, areaMilli: 3000, billableMilli: 3000, minAreaApplied: false });
    expect(lineTotalCents(3000, a.billableMilli)).toBe(9000);
  });
  it('área mínima faturável por peça (ADR-019)', () => {
    const a = squareMeterArea(400, 500, 1, 1000); // 0,40 × 0,50 = 0,200 m², mínimo 1,000
    expect(a).toEqual({ pieceAreaMilli: 200, areaMilli: 200, billableMilli: 1000, minAreaApplied: true });
    expect(lineTotalCents(3000, a.billableMilli)).toBe(3000);
    const three = squareMeterArea(400, 500, 3, 1000);
    expect(three.billableMilli).toBe(3000);
    expect(three.areaMilli).toBe(600);
  });
  it('arredonda a área da peça half-up em milésimos', () => {
    expect(pieceAreaMilli(1205, 1005)).toBe(1211); // 1211,025
    expect(pieceAreaMilli(1005, 1500)).toBe(1508); // 1507,5 → 1508
  });
  it('peso: ceil(g × milli / 1000); KG = gramas', () => {
    expect(estimateWeightGrams('LINEAR_METER', 250, 5000)).toBe(1250);
    expect(estimateWeightGrams('SQUARE_METER', 460, 3000)).toBe(1380);
    expect(estimateWeightGrams('LINEAR_METER', 250, 5350)).toBe(1338);
    expect(estimateWeightGrams('KG', 0, 1500)).toBe(1500);
  });
  it('passo inválido 5,05 / 0,10 → sugestões 5,00 e 5,10', () => {
    expect(checkStep(5050, 1000, 50000, 100)).toEqual({ ok: false, reason: 'step', suggestions: [5000, 5100] });
    expect(checkStep(5000, 1000, 50000, 100)).toEqual({ ok: true });
    expect(checkStep(500, 1000, 50000, 100)).toMatchObject({ ok: false, reason: 'below_min', suggestions: [1000] });
    expect(checkStep(60000, 1000, 50000, 100)).toMatchObject({ ok: false, reason: 'above_max', suggestions: [50000] });
  });
  it('mensagem de passo no formato do backend', () => {
    expect(stepMessage(100, [5000, 5100], 'LINEAR_METER').replace(/ /g, ' ')).toBe('Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.');
  });
});

describe('checkConfiguration + prévia local', () => {
  it('LINEAR_METER 5 m → R$ 79,50 com faixa base', () => {
    const c = checkConfiguration(base, draft({ quantity: '5' }));
    expect(c.ok).toBe(true);
    if (!c.ok) return;
    expect(c.body).toEqual({ quantity: 5 });
    const tiers = [
      { min_quantity: 1, max_quantity: 9.9, unit_price_cents: 1590, price_source: 'base' as const },
      { min_quantity: 10, max_quantity: 49.9, unit_price_cents: 1490, price_source: 'tier' as const },
    ];
    expect(computeLocalPreview('LINEAR_METER', c, { unit_price_cents: 1590, tiers }, 250)).toMatchObject({ unitPriceCents: 1590, lineTotalCents: 7950, weightGrams: 1250 });
    const c10 = checkConfiguration(base, draft({ quantity: '10' }));
    expect(c10.ok).toBe(true);
    const total10 = c10.ok ? computeLocalPreview('LINEAR_METER', c10, { unit_price_cents: 1590, tiers }, 250).lineTotalCents : null;
    expect(total10).toBe(14900);
  });
  it('LINEAR_METER 5,05 → erro de passo com sugestões', () => {
    const c = checkConfiguration(base, draft({ quantity: '5,05' }));
    expect(c.ok).toBe(false);
    if (c.ok) return;
    expect(c.errors.quantity?.message.replace(/ /g, ' ')).toBe('Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.');
    expect(c.errors.quantity?.suggestionsMilli).toEqual([5000, 5100]);
  });
  it('UNIT exige inteiro', () => {
    const c = checkConfiguration({ ...base, sale_unit: 'UNIT', input: 'integer', quantity_step: 1, max_quantity: null, fixed_width_m: null }, draft({ quantity: '2,5' }));
    expect(c.ok ? null : c.errors.quantity?.message).toBe('Quantidade deve ser inteira.');
  });
  it('SQUARE_METER variável 1,20 × 2,50 × 1', () => {
    const c = checkConfiguration(lona, draft({ width: '1,20', height: '2,50', pieces: '1' }));
    expect(c.ok).toBe(true);
    if (!c.ok) return;
    expect(c.body).toEqual({ width_m: 1.2, height_m: 2.5, pieces: 1 });
    expect(c.billableMilli).toBe(3000);
  });
  it('SQUARE_METER com largura fixa não envia width_m', () => {
    const c = checkConfiguration({ ...lona, fixed_width_m: 3.2, min_width_m: null, max_width_m: null }, draft({ width: '3,20', height: '2', pieces: '1' }));
    expect(c.ok && c.body).toEqual({ height_m: 2, pieces: 1 });
    expect(c.ok && c.billableMilli).toBe(6400);
  });
  it('SQUARE_METER: > 2 casas, fora da faixa e sugestão de inverter medidas', () => {
    const a = checkConfiguration(lona, draft({ width: '1,205', height: '2' }));
    expect(a.ok ? '' : a.errors.width_m?.message).toMatch(/2 casas/);
    const b = checkConfiguration(lona, draft({ width: '5', height: '2' }));
    expect(b.ok ? '' : b.errors.width_m?.message).toMatch(/Tente inverter largura e altura/);
  });
});
