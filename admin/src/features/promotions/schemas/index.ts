import { z } from 'zod';

export const promotionSchema = z
  .object({
    name: z.string().trim().min(1, 'Informe o nome.').max(150, 'No máximo 150 caracteres.'),
    description: z.string().max(500, 'No máximo 500 caracteres.'),
    discount_type: z.enum(['percent', 'fixed']),
    value_bp: z.number().int().nullable(),
    value_cents: z.number().int().nullable(),
    scope: z.enum(['all', 'targeted']),
    starts_at: z.string().min(1, 'Informe o início.'),
    ends_at: z.string(),
    is_active: z.boolean(),
    priority: z.number().int().nullable(),
    product_ids: z.array(z.number()),
    category_ids: z.array(z.number()),
    brand_ids: z.array(z.number()),
  })
  .superRefine((v, ctx) => {
    if (v.discount_type === 'percent' && (v.value_bp === null || v.value_bp < 1 || v.value_bp > 10000)) ctx.addIssue({ code: 'custom', path: ['value_bp'], message: 'Informe um percentual entre 0,01% e 100%.' });
    if (v.discount_type === 'fixed' && (v.value_cents === null || v.value_cents <= 0)) ctx.addIssue({ code: 'custom', path: ['value_cents'], message: 'Informe o valor do desconto.' });
    if (v.ends_at && v.ends_at <= v.starts_at) ctx.addIssue({ code: 'custom', path: ['ends_at'], message: 'O fim deve ser depois do início.' });
    if (v.scope === 'targeted' && v.product_ids.length + v.category_ids.length + v.brand_ids.length === 0) ctx.addIssue({ code: 'custom', path: ['scope'], message: 'Selecione ao menos um produto, categoria ou marca.' });
  });
export type PromotionForm = z.infer<typeof promotionSchema>;

export const couponSchema = z
  .object({
    code: z.string().trim().regex(/^[A-Za-z0-9_-]{3,40}$/, 'Código: 3 a 40 caracteres (A–Z, 0–9, _ e -).'),
    description: z.string().max(255, 'No máximo 255 caracteres.'),
    type: z.enum(['percent', 'fixed', 'free_shipping']),
    value_bp: z.number().int().nullable(),
    value_cents: z.number().int().nullable(),
    min_order_cents: z.number().int().min(0, 'Não pode ser negativo.').nullable(),
    max_discount_cents: z.number().int().nullable(),
    starts_at: z.string(),
    ends_at: z.string(),
    usage_limit: z.number().int().nullable(),
    usage_limit_per_customer: z.number().int().nullable(),
    is_active: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.type === 'percent' && (v.value_bp === null || v.value_bp < 1 || v.value_bp > 10000)) ctx.addIssue({ code: 'custom', path: ['value_bp'], message: 'Informe um percentual entre 0,01% e 100%.' });
    if (v.type === 'fixed' && (v.value_cents === null || v.value_cents <= 0)) ctx.addIssue({ code: 'custom', path: ['value_cents'], message: 'Informe o valor do desconto.' });
    if (v.max_discount_cents !== null && v.max_discount_cents <= 0) ctx.addIssue({ code: 'custom', path: ['max_discount_cents'], message: 'Deve ser maior que zero.' });
    if (v.usage_limit !== null && v.usage_limit <= 0) ctx.addIssue({ code: 'custom', path: ['usage_limit'], message: 'Deve ser maior que zero.' });
    if (v.usage_limit_per_customer !== null && v.usage_limit_per_customer <= 0) ctx.addIssue({ code: 'custom', path: ['usage_limit_per_customer'], message: 'Deve ser maior que zero.' });
    if (v.starts_at && v.ends_at && v.ends_at <= v.starts_at) ctx.addIssue({ code: 'custom', path: ['ends_at'], message: 'O fim deve ser depois do início.' });
  });
export type CouponForm = z.infer<typeof couponSchema>;
