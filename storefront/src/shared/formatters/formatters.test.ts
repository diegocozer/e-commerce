import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatCountdown } from './date';
import { formatBRL, parseMoneyToCents, spokenBRL } from './money';
import { formatCEP, isValidCEP } from './postalCode';
import { formatPhone } from './phone';
import { formatArea, formatConfiguration, formatMeters, formatQuantity, formatWeight } from './quantity';

const n = (s: string) => s.replace(/ /g, ' ');

describe('money', () => {
  it('formata centavos em BRL', () => {
    expect(n(formatBRL(7950))).toBe('R$ 79,50');
    expect(n(formatBRL(123456))).toBe('R$ 1.234,56');
    expect(n(formatBRL(-1695))).toBe('-R$ 16,95');
  });
  it('lê valores em reais sem float', () => {
    expect(parseMoneyToCents('1.234,56')).toBe(123456);
    expect(parseMoneyToCents('R$ 10')).toBe(1000);
    expect(parseMoneyToCents('15.9')).toBe(1590);
    expect(parseMoneyToCents('abc')).toBeNull();
  });
  it('lê por extenso', () => {
    expect(spokenBRL(1590)).toBe('15 reais e 90 centavos');
    expect(spokenBRL(100)).toBe('1 real');
  });
});

describe('quantidades e unidades', () => {
  it('formata por unidade de venda', () => {
    expect(n(formatQuantity(5, 'UNIT'))).toBe('5 un');
    expect(n(formatQuantity(5.5, 'LINEAR_METER'))).toBe('5,5 m');
    expect(n(formatQuantity(2.25, 'LINEAR_METER'))).toBe('2,25 m');
    expect(n(formatQuantity(3, 'SQUARE_METER'))).toBe('3,00 m²');
    expect(n(formatQuantity(1, 'ROLL'))).toBe('1 rolo');
    expect(n(formatQuantity(2, 'ROLL'))).toBe('2 rolos');
    expect(n(formatQuantity(2.5, 'KG'))).toBe('2,5 kg');
    expect(n(formatQuantity(1, 'BOX'))).toBe('1 caixa');
    expect(n(formatQuantity(3, 'BOX'))).toBe('3 caixas');
  });
  it('usa espaço não separável entre número e unidade', () => {
    expect(formatQuantity(5, 'LINEAR_METER')).toBe('5 m');
  });
  it('área sempre com 2 casas; dimensão com 2 casas', () => {
    expect(n(formatArea(0.2))).toBe('0,20 m²');
    expect(n(formatMeters(1.22))).toBe('1,22 m');
  });
  it('peso: g / 1 casa até 10 kg / inteiro acima', () => {
    expect(n(formatWeight(850))).toBe('850 g');
    expect(n(formatWeight(1200))).toBe('1,2 kg');
    expect(n(formatWeight(1250))).toBe('1,3 kg');
    expect(n(formatWeight(12000))).toBe('12 kg');
  });
  it('configuração m²', () => {
    expect(n(formatConfiguration({ quantity: null, width_m: 1.2, height_m: 2.5, pieces: 1 }, 'SQUARE_METER', 3))).toBe('1,20 m × 2,50 m × 1 peça = 3,00 m²');
    expect(n(formatConfiguration({ quantity: null, width_m: 1.2, height_m: 2.5, pieces: 4 }, 'SQUARE_METER'))).toBe('1,20 m × 2,50 m × 4 peças');
  });
});

describe('CEP, telefone e datas', () => {
  it('CEP', () => {
    expect(formatCEP('89010000')).toBe('89010-000');
    expect(isValidCEP('89010-000')).toBe(true);
    expect(isValidCEP('00000000')).toBe(false);
    expect(isValidCEP('8901')).toBe(false);
  });
  it('telefone', () => {
    expect(formatPhone('47999990001')).toBe('(47) 99999-0001');
    expect(formatPhone('4733330000')).toBe('(47) 3333-0000');
  });
  it('datas em America/Sao_Paulo', () => {
    expect(formatDate('2026-09-24T13:00:00Z')).toBe('24/09/2026');
    expect(formatDateTime('2026-09-24T13:42:00Z')).toBe('24/09/2026 10:42');
    expect(formatDate('2026-09-26')).toBe('26/09/2026');
    expect(formatCountdown(28 * 60_000 + 41_000)).toBe('28:41');
  });
});
