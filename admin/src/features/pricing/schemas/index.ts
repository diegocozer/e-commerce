import { z } from 'zod';

export const priceListSchema = z.object({
  code: z.string().trim().regex(/^[a-z0-9_]{1,40}$/, 'Use letras minúsculas, números e _ (até 40).'),
  name: z.string().trim().min(1, 'Informe o nome.').max(120, 'No máximo 120 caracteres.'),
  kind: z.enum(['retail', 'wholesale', 'reseller', 'custom']),
  discount_bp: z.number().int().min(1, 'Entre 0,01% e 99,99%.').max(9999, 'Entre 0,01% e 99,99%.').nullable(),
  is_default: z.boolean(),
  is_active: z.boolean(),
});
export type PriceListForm = z.infer<typeof priceListSchema>;

export const customerPriceSchema = z
  .object({
    target: z.enum(['customer', 'company']),
    customer_id: z.number().nullable(),
    company_id: z.number().nullable(),
    variant_id: z.number().nullable(),
    price_cents: z.number().int().nullable(),
    starts_at: z.string(),
    ends_at: z.string(),
  })
  .superRefine((v, ctx) => {
    if (v.target === 'customer' && v.customer_id === null) ctx.addIssue({ code: 'custom', path: ['customer_id'], message: 'Selecione o cliente.' });
    if (v.target === 'company' && v.company_id === null) ctx.addIssue({ code: 'custom', path: ['company_id'], message: 'Selecione a empresa.' });
    if (v.variant_id === null) ctx.addIssue({ code: 'custom', path: ['variant_id'], message: 'Selecione a variante.' });
    if (v.price_cents === null || v.price_cents <= 0) ctx.addIssue({ code: 'custom', path: ['price_cents'], message: 'Informe o preço.' });
    if (v.starts_at && v.ends_at && v.ends_at <= v.starts_at) ctx.addIssue({ code: 'custom', path: ['ends_at'], message: 'O fim deve ser depois do início.' });
  });
export type CustomerPriceForm = z.infer<typeof customerPriceSchema>;
