import { z } from 'zod';
import type { ShippingMethod, ShippingRule } from '@/shared/api/types';
import { isoToLocalInput, localInputToIso } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { formatDecimal, parseDecimal } from '@/shared/formatters/quantity';
import { cm3ToM3, gramsToKg, kgToGrams, m3ToCm3 } from './units';

export const ruleSchema = (methods: ShippingMethod[]) =>
  z
    .object({
      method_id: z.number().nullable(),
      zone_id: z.number().nullable(),
      name: z.string().trim(),
      priority: z.number().int().nullable(),
      min_weight_kg: z.string(),
      max_weight_kg: z.string(),
      min_subtotal_cents: z.number().int().nullable(),
      max_subtotal_cents: z.number().int().nullable(),
      min_volume_m3: z.string(),
      max_volume_m3: z.string(),
      max_package_length_cm: z.string(),
      price_type: z.enum(['fixed', 'per_kg', 'fixed_plus_per_kg', 'percentage_of_subtotal', 'free']),
      price_cents: z.number().int().nullable(),
      per_kg_cents: z.number().int().nullable(),
      percentage_bp: z.number().int().nullable(),
      min_price_cents: z.number().int().nullable(),
      max_price_cents: z.number().int().nullable(),
      delivery_days_min: z.number().int().nullable(),
      delivery_days_max: z.number().int().nullable(),
      valid_from: z.string(),
      valid_until: z.string(),
      is_active: z.boolean(),
    })
    .superRefine((v, ctx) => {
      const add = (path: string, message: string) => ctx.addIssue({ code: 'custom', path: [path], message });
      if (v.method_id === null) add('method_id', 'Selecione o método.');
      else {
        const m = methods.find((x) => x.id === v.method_id);
        if (m && m.type !== 'own_delivery' && m.type !== 'table_rate') add('method_id', 'Regras só se aplicam a entrega própria ou frete por tabela.');
      }
      if (v.name.length < 1 || v.name.length > 150) add('name', 'Informe o nome (até 150 caracteres).');
      if (v.priority === null) add('priority', 'Informe a prioridade.');
      const pair = (minKey: string, maxKey: string, min: number | null, max: number | null, label: string) => {
        if (min !== null && min < 0) add(minKey, 'Não pode ser negativo.');
        if (max !== null && max < 0) add(maxKey, 'Não pode ser negativo.');
        if (min !== null && max !== null && max < min) add(maxKey, `${label} máximo deve ser ≥ mínimo.`);
      };
      const wMin = v.min_weight_kg ? kgToGrams(v.min_weight_kg) : null;
      const wMax = v.max_weight_kg ? kgToGrams(v.max_weight_kg) : null;
      if (v.min_weight_kg && wMin === null) add('min_weight_kg', 'Peso inválido (até 3 casas).');
      if (v.max_weight_kg && wMax === null) add('max_weight_kg', 'Peso inválido (até 3 casas).');
      pair('min_weight_kg', 'max_weight_kg', wMin, wMax, 'O peso');
      pair('min_subtotal_cents', 'max_subtotal_cents', v.min_subtotal_cents, v.max_subtotal_cents, 'O subtotal');
      const vMin = v.min_volume_m3 ? m3ToCm3(v.min_volume_m3) : null;
      const vMax = v.max_volume_m3 ? m3ToCm3(v.max_volume_m3) : null;
      if (v.min_volume_m3 && vMin === null) add('min_volume_m3', 'Volume inválido.');
      if (v.max_volume_m3 && vMax === null) add('max_volume_m3', 'Volume inválido.');
      pair('min_volume_m3', 'max_volume_m3', vMin, vMax, 'O volume');
      if (v.max_package_length_cm && (parseDecimal(v.max_package_length_cm, 1) === null || Number(v.max_package_length_cm) <= 0)) add('max_package_length_cm', 'Deve ser maior que zero (1 casa).');
      switch (v.price_type) {
        case 'fixed':
          if (v.price_cents === null || v.price_cents < 0) add('price_cents', 'Informe o preço (use "Grátis" para frete zero).');
          break;
        case 'per_kg':
          if (v.per_kg_cents === null || v.per_kg_cents <= 0) add('per_kg_cents', 'Informe o valor por kg.');
          break;
        case 'fixed_plus_per_kg':
          if (v.price_cents === null || v.price_cents < 0) add('price_cents', 'Informe a parte fixa.');
          if (v.per_kg_cents === null || v.per_kg_cents <= 0) add('per_kg_cents', 'Informe o valor por kg.');
          break;
        case 'percentage_of_subtotal':
          if (v.percentage_bp === null || v.percentage_bp < 1 || v.percentage_bp > 10000) add('percentage_bp', 'Informe um percentual entre 0,01% e 100%.');
          break;
        case 'free':
          break;
      }
      if (v.price_type !== 'free') pair('min_price_cents', 'max_price_cents', v.min_price_cents, v.max_price_cents, 'O preço');
      pair('delivery_days_min', 'delivery_days_max', v.delivery_days_min, v.delivery_days_max, 'O prazo');
      if (v.valid_from && v.valid_until && v.valid_until <= v.valid_from) add('valid_until', 'O fim da vigência deve ser depois do início.');
    });
export type RuleForm = z.infer<ReturnType<typeof ruleSchema>>;

export function emptyRule(methodId: number | null, zoneId: number | null): RuleForm {
  return {
    method_id: methodId, zone_id: zoneId, name: '', priority: 10, min_weight_kg: '', max_weight_kg: '', min_subtotal_cents: null, max_subtotal_cents: null,
    min_volume_m3: '', max_volume_m3: '', max_package_length_cm: '', price_type: 'fixed', price_cents: null, per_kg_cents: null, percentage_bp: null,
    min_price_cents: null, max_price_cents: null, delivery_days_min: null, delivery_days_max: null, valid_from: '', valid_until: '', is_active: true,
  };
}

export function ruleToForm(r: ShippingRule): RuleForm {
  return {
    method_id: r.method_id, zone_id: r.zone_id, name: r.name, priority: r.priority, min_weight_kg: gramsToKg(r.min_weight_grams), max_weight_kg: gramsToKg(r.max_weight_grams),
    min_subtotal_cents: r.min_subtotal_cents, max_subtotal_cents: r.max_subtotal_cents, min_volume_m3: cm3ToM3(r.min_volume_cm3), max_volume_m3: cm3ToM3(r.max_volume_cm3),
    max_package_length_cm: r.max_package_length_cm === null ? '' : String(r.max_package_length_cm), price_type: r.price_type, price_cents: r.price_cents, per_kg_cents: r.per_kg_cents || null,
    percentage_bp: r.percentage_bp || null, min_price_cents: r.min_price_cents, max_price_cents: r.max_price_cents, delivery_days_min: r.delivery_days_min, delivery_days_max: r.delivery_days_max,
    valid_from: isoToLocalInput(r.valid_from), valid_until: isoToLocalInput(r.valid_until), is_active: r.is_active,
  };
}

export function ruleFormToPayload(v: RuleForm): Record<string, unknown> {
  const t = v.price_type;
  return {
    method_id: v.method_id, zone_id: v.zone_id, name: v.name, priority: v.priority,
    min_weight_grams: v.min_weight_kg ? kgToGrams(v.min_weight_kg) : null, max_weight_grams: v.max_weight_kg ? kgToGrams(v.max_weight_kg) : null,
    min_subtotal_cents: v.min_subtotal_cents, max_subtotal_cents: v.max_subtotal_cents,
    min_volume_cm3: v.min_volume_m3 ? m3ToCm3(v.min_volume_m3) : null, max_volume_cm3: v.max_volume_m3 ? m3ToCm3(v.max_volume_m3) : null,
    max_package_length_cm: v.max_package_length_cm ? Number(v.max_package_length_cm) : null,
    price_type: t,
    price_cents: t === 'fixed' || t === 'fixed_plus_per_kg' ? (v.price_cents ?? 0) : 0,
    per_kg_cents: t === 'per_kg' || t === 'fixed_plus_per_kg' ? (v.per_kg_cents ?? 0) : 0,
    percentage_bp: t === 'percentage_of_subtotal' ? (v.percentage_bp ?? 0) : 0,
    min_price_cents: t === 'free' ? null : v.min_price_cents, max_price_cents: t === 'free' ? null : v.max_price_cents,
    delivery_days_min: v.delivery_days_min, delivery_days_max: v.delivery_days_max,
    valid_from: localInputToIso(v.valid_from), valid_until: localInputToIso(v.valid_until), is_active: v.is_active,
  };
}

const kg = (g: number) => `${formatDecimal(g / 1000, 0, 3)} kg`;
const m3 = (c: number) => `${formatDecimal(c / 1_000_000, 0, 3)} m³`;
function range(min: number | null, max: number | null, fmt: (n: number) => string, prefix = '', short: (n: number) => string = fmt): string | null {
  if (min !== null && max !== null) return `${prefix}${short(min)}–${fmt(max)}`;
  if (max !== null) return `${prefix}≤ ${fmt(max)}`;
  if (min !== null) return `${prefix}≥ ${fmt(min)}`;
  return null;
}

/** Chips de condição em linguagem natural (UX §5.10.3): "≤ 10 kg", "Subtotal ≥ R$ 500,00". */
export function ruleConditionChips(r: Pick<ShippingRule, 'min_weight_grams' | 'max_weight_grams' | 'min_subtotal_cents' | 'max_subtotal_cents' | 'min_volume_cm3' | 'max_volume_cm3' | 'max_package_length_cm'>): string[] {
  return [
    range(r.min_weight_grams, r.max_weight_grams, kg, '', (g) => formatDecimal(g / 1000, 0, 3)),
    range(r.min_subtotal_cents, r.max_subtotal_cents, (c) => formatBRL(c), 'Subtotal '),
    range(r.min_volume_cm3, r.max_volume_cm3, m3, 'Volume ', (c) => formatDecimal(c / 1_000_000, 0, 3)),
    r.max_package_length_cm !== null ? `Maior dimensão ≤ ${formatDecimal(r.max_package_length_cm, 0, 1)} cm` : null,
  ].filter((x): x is string => x !== null);
}

export function rulePriceLabel(r: Pick<ShippingRule, 'price_type' | 'price_cents' | 'per_kg_cents' | 'percentage_bp'>): string {
  switch (r.price_type) {
    case 'free':
      return 'Grátis';
    case 'fixed':
      return r.price_cents === 0 ? 'Grátis' : formatBRL(r.price_cents);
    case 'per_kg':
      return `${formatBRL(r.per_kg_cents)}/kg`;
    case 'fixed_plus_per_kg':
      return `${formatBRL(r.price_cents)} + ${formatBRL(r.per_kg_cents)}/kg`;
    case 'percentage_of_subtotal':
      return `${formatDecimal(r.percentage_bp / 100, 0, 2)}% do subtotal`;
  }
}

export function daysLabel(min: number | null, max: number | null): string {
  if (min === null && max === null) return 'Prazo do método';
  if (min === max || max === null) return min === 0 ? 'Mesmo dia' : `${min} ${min === 1 ? 'dia útil' : 'dias úteis'}`;
  return `${min ?? 0} a ${max} dias úteis`;
}
