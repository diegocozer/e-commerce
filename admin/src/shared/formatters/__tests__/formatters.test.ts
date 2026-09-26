import { describe, expect, it } from 'vitest';
import { centsToInput, formatBp, formatBRL, parseBRLToCents, parsePercentToBp } from '../money';
import { formatArea, formatConfiguration, formatQuantity, formatWeight, milliToApi, parseDecimal, toMilli } from '../quantity';
import { formatDate, formatDateTime, localInputToIso, isoToLocalInput } from '../date';
import { formatCEP, formatCNPJ, formatCPF, formatPhone } from '../document';

const nb = (s: string) => s.replace(/ /g, ' ');

describe('dinheiro (centavos inteiros, sem float)', () => {
  it.each([
    ['1.234,56', 123456],
    ['1234,56', 123456],
    ['1234.56', 123456],
    ['R$ 15,9', 1590],
    ['15', 1500],
    ['0,01', 1],
    ['1.234', 123400],
    ['0,1', 10],
    ['19,99', 1999],
    ['1.000.000,00', 100000000],
  ])('parseBRLToCents(%s) = %i', (input, cents) => {
    expect(parseBRLToCents(input)).toBe(cents);
  });

  it.each(['', 'abc', '1,234', '1,2,3', '12.34.5', '1.23,4.5'])('rejeita %s', (input) => {
    expect(parseBRLToCents(input)).toBeNull();
  });

  it('evita erro de ponto flutuante clássico (0,29 × 100)', () => {
    expect(parseBRLToCents('0,29')).toBe(29);
    expect(parseBRLToCents('1,15')).toBe(115);
    expect(parseBRLToCents('4,35')).toBe(435);
  });

  it('formata e converte de volta', () => {
    expect(nb(formatBRL(123456))).toBe('R$ 1.234,56');
    expect(nb(formatBRL(-1695))).toBe('-R$ 16,95');
    expect(centsToInput(123456)).toBe('1.234,56');
    expect(centsToInput(5)).toBe('0,05');
    expect(parseBRLToCents(centsToInput(987654321))).toBe(987654321);
  });

  it('basis points', () => {
    expect(formatBp(1250)).toBe('12,5%');
    expect(formatBp(-400, { signed: true })).toBe('-4%');
    expect(parsePercentToBp('10,5')).toBe(1050);
    expect(parsePercentToBp('100')).toBe(10000);
    expect(parsePercentToBp('1,234')).toBeNull();
  });
});

describe('quantidades (3 casas)', () => {
  it('parseDecimal normaliza para a API', () => {
    expect(parseDecimal('1,5')).toBe('1.5');
    expect(parseDecimal('1.234,5')).toBe('1234.5');
    expect(parseDecimal('2.350')).toBe('2.35');
    expect(parseDecimal('0,001')).toBe('0.001');
    expect(parseDecimal('1,2345')).toBeNull();
    expect(parseDecimal('1,5', 0)).toBeNull();
    expect(parseDecimal('abc')).toBeNull();
  });

  it('toMilli / milliToApi com inteiros', () => {
    expect(toMilli('1,25')).toBe(1250);
    expect(toMilli('0.1')).toBe(100);
    expect(milliToApi(5500)).toBe(5.5);
    expect(milliToApi(2350)).toBe(2.35);
  });

  it('formata por unidade de venda', () => {
    expect(nb(formatQuantity(5.5, 'LINEAR_METER'))).toBe('5,5 m');
    expect(nb(formatQuantity(3, 'SQUARE_METER'))).toBe('3,00 m²');
    expect(nb(formatQuantity(1, 'ROLL'))).toBe('1 rolo');
    expect(nb(formatQuantity(3, 'BOX'))).toBe('3 caixas');
    expect(nb(formatQuantity(5, 'UNIT'))).toBe('5 un');
    expect(nb(formatQuantity(2.5, 'KG'))).toBe('2,5 kg');
    expect(nb(formatArea(3))).toBe('3,00 m²');
  });

  it('peso em gramas', () => {
    expect(nb(formatWeight(850))).toBe('850 g');
    expect(nb(formatWeight(1200))).toBe('1,2 kg');
    expect(nb(formatWeight(12400))).toBe('12 kg');
  });

  it('configuração m²: "1,20 m × 2,50 m × 1 peça = 3,00 m²"', () => {
    expect(nb(formatConfiguration({ quantity: null, width_m: 1.2, height_m: 2.5, pieces: 1 }, 'SQUARE_METER', 3))).toBe('1,20 m × 2,50 m × 1 peça = 3,00 m²');
    expect(nb(formatConfiguration({ quantity: null, width_m: 1.2, height_m: 2.5, pieces: 4 }, 'SQUARE_METER', 12))).toBe('1,20 m × 2,50 m × 4 peças = 12,00 m²');
    expect(nb(formatConfiguration({ quantity: 5, width_m: null, height_m: null, pieces: null }, 'LINEAR_METER'))).toBe('5 m');
  });
});

describe('datas (America/Sao_Paulo) e documentos', () => {
  it('converte UTC para SP', () => {
    expect(formatDateTime('2026-09-24T13:00:00Z')).toBe('24/09/2026 10:00');
    expect(formatDate('2026-09-24')).toBe('24/09/2026');
    expect(formatDate('2026-09-25T01:00:00Z')).toBe('24/09/2026');
  });
  it('datetime-local ↔ ISO', () => {
    expect(localInputToIso('2026-10-01T00:00')).toBe('2026-10-01T03:00:00Z');
    expect(isoToLocalInput('2026-10-01T03:00:00Z')).toBe('2026-10-01T00:00');
  });
  it('máscaras', () => {
    expect(formatCEP('89010000')).toBe('89010-000');
    expect(formatCPF('12345678909')).toBe('123.456.789-09');
    expect(formatCNPJ('12345678000190')).toBe('12.345.678/0001-90');
    expect(formatCNPJ('12ABC34501DE35')).toBe('12.ABC.345/01DE-35');
    expect(formatPhone('47999990000')).toBe('(47) 99999-0000');
  });
});
