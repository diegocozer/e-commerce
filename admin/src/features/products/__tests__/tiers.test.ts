import { describe, expect, it } from 'vitest';
import { validateTiers } from '../components/PriceTiersSection';
import { formToPayload, parseAttributes, emptyProduct } from '../schemas/product';

describe('faixas de preço', () => {
  it('mínimos crescentes e preço não crescente (RN-PRC-005)', () => {
    expect(validateTiers([{ key: 1, min: '1', price: 3000 }, { key: 2, min: '11', price: 2700 }, { key: 3, min: '51', price: 2400 }])).toEqual({});
    expect(validateTiers([{ key: 1, min: '10', price: 3000 }, { key: 2, min: '5', price: 2700 }])[1]).toMatch(/deve começar acima/);
    expect(validateTiers([{ key: 1, min: '1', price: 2000 }, { key: 2, min: '10', price: 2500 }])[1]).toMatch(/não pode aumentar/);
  });
});

describe('payload de produto', () => {
  it('atributos "k: v; k2: v2"', () => {
    expect(parseAttributes('cor: Branco; acabamento: Brilho')).toEqual({ cor: 'Branco', acabamento: 'Brilho' });
    expect(parseAttributes('sem-dois-pontos')).toBeNull();
  });
  it('omite preço sem prices.manage em variante existente e estoque inicial sem inventory.move', () => {
    const f = { ...emptyProduct(), name: 'X', primary_category_id: 1 };
    f.variants = [{ ...f.variants[0], id: 5, sku: 'a-1', price_cents: 100, weight_grams: 1, initial_stock: '3' }, { ...f.variants[0], sku: 'b-2', price_cents: 200, weight_grams: 1, initial_stock: '4' }];
    const p = formToPayload(f, { canPrices: false, canMoveStock: false }) as { variants: Record<string, unknown>[] };
    expect(p.variants[0]).not.toHaveProperty('price_cents');
    expect(p.variants[0].sku).toBe('A-1');
    expect(p.variants[1].price_cents).toBe(200);
    expect(p.variants[1]).not.toHaveProperty('initial_stock');
    const q = formToPayload(f, { canPrices: true, canMoveStock: true }) as { variants: Record<string, unknown>[] };
    expect(q.variants[1].initial_stock).toBe(4);
    expect(q.variants[0]).not.toHaveProperty('initial_stock');
  });
});
