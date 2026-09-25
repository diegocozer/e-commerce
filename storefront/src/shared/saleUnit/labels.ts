import type { SaleUnit } from '../api/types';

export const NBSP = ' ';

export const SALE_UNIT_LABEL: Record<SaleUnit, string> = {
  UNIT: 'Unidade',
  LINEAR_METER: 'Metro linear',
  SQUARE_METER: 'Metro quadrado',
  ROLL: 'Rolo',
  KG: 'Quilograma',
  BOX: 'Caixa',
};

/** Sufixo curto de preço (UX §4.0 UnitSuffix). */
export const UNIT_SUFFIX: Record<SaleUnit, string> = {
  UNIT: '/un',
  LINEAR_METER: '/m',
  SQUARE_METER: '/m²',
  ROLL: '/rolo',
  KG: '/kg',
  BOX: '/cx',
};

/** Unidade por extenso ("/ metro") na página de produto. */
export const UNIT_WORD: Record<SaleUnit, string> = {
  UNIT: 'unidade',
  LINEAR_METER: 'metro',
  SQUARE_METER: 'm²',
  ROLL: 'rolo',
  KG: 'kg',
  BOX: 'caixa',
};

/** Para aria-label: "por metro". */
export const UNIT_SPOKEN: Record<SaleUnit, string> = {
  UNIT: 'por unidade',
  LINEAR_METER: 'por metro',
  SQUARE_METER: 'por metro quadrado',
  ROLL: 'por rolo',
  KG: 'por quilo',
  BOX: 'por caixa',
};

/** Rótulo do campo de quantidade: "Quantidade (metros)". */
export const QUANTITY_FIELD_LABEL: Record<SaleUnit, string> = {
  UNIT: 'Quantidade (unidades)',
  LINEAR_METER: 'Quantidade (metros)',
  SQUARE_METER: 'Peças',
  ROLL: 'Quantidade (rolos)',
  KG: 'Quantidade (kg)',
  BOX: 'Quantidade (caixas)',
};

export const INPUT_ADORNMENT: Record<SaleUnit, string> = {
  UNIT: 'un',
  LINEAR_METER: 'm',
  SQUARE_METER: 'peças',
  ROLL: 'rolos',
  KG: 'kg',
  BOX: 'cx',
};

export function isIntegerUnit(unit: SaleUnit): boolean {
  return unit === 'UNIT' || unit === 'ROLL' || unit === 'BOX';
}

/** schema.org unitCode (UX §4.4.8). */
export const UNIT_CODE: Record<SaleUnit, string> = {
  UNIT: 'C62',
  LINEAR_METER: 'MTR',
  SQUARE_METER: 'MTK',
  ROLL: 'C62',
  KG: 'KGM',
  BOX: 'C62',
};
