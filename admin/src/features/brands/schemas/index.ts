import { z } from 'zod';
import { RESERVED_SLUGS, SLUG_RE } from '@/shared/lib/validation';

export const brandSchema = z.object({
  name: z.string().trim().min(2, 'Informe o nome (2 a 120 caracteres).').max(120, 'No máximo 120 caracteres.'),
  slug: z.string().trim().refine((v) => v === '' || (SLUG_RE.test(v) && !RESERVED_SLUGS.includes(v)), 'Slug inválido ou reservado.'),
  is_active: z.boolean(),
});
export type BrandForm = z.infer<typeof brandSchema>;
