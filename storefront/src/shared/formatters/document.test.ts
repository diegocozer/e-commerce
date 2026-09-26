import { describe, expect, it } from 'vitest';
import { formatCNPJ, formatCPF, isValidCNPJ, isValidCPF, normalizeCNPJ } from './document';

describe('CPF', () => {
  it('valida dígitos verificadores', () => {
    expect(isValidCPF('529.982.247-25')).toBe(true);
    expect(isValidCPF('52998224725')).toBe(true);
    expect(isValidCPF('529.982.247-24')).toBe(false);
  });
  it('rejeita sequências repetidas e tamanho errado', () => {
    expect(isValidCPF('111.111.111-11')).toBe(false);
    expect(isValidCPF('1234567890')).toBe(false);
  });
  it('máscara', () => {
    expect(formatCPF('52998224725')).toBe('529.982.247-25');
    expect(formatCPF('529982')).toBe('529.982');
  });
});

describe('CNPJ', () => {
  it('valida CNPJ numérico', () => {
    expect(isValidCNPJ('11.222.333/0001-81')).toBe(true);
    expect(isValidCNPJ('11222333000181')).toBe(true);
    expect(isValidCNPJ('11.222.333/0001-80')).toBe(false);
    expect(isValidCNPJ('00.000.000/0000-00')).toBe(false);
  });
  it('valida CNPJ alfanumérico (ADR-026a)', () => {
    expect(isValidCNPJ('12.ABC.345/01DE-35')).toBe(true);
    expect(isValidCNPJ('12abc34501de35')).toBe(true);
    expect(isValidCNPJ('12.ABC.345/01DE-36')).toBe(false);
    // DV precisa ser numérico
    expect(isValidCNPJ('12ABC34501DEA5')).toBe(false);
  });
  it('máscara e normalização', () => {
    expect(formatCNPJ('11222333000181')).toBe('11.222.333/0001-81');
    expect(formatCNPJ('12abc34501de35')).toBe('12.ABC.345/01DE-35');
    expect(normalizeCNPJ('12.ABC.345/01DE-35')).toBe('12ABC34501DE35');
  });
});
