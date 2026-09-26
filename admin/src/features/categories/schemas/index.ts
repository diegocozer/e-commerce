import { z } from 'zod';
import { RESERVED_SLUGS, SLUG_RE } from '@/shared/lib/validation';

export const categorySchema = z.object({
  name: z.string().trim().min(2, 'Informe o nome (2 a 120 caracteres).').max(120, 'No máximo 120 caracteres.'),
  slug: z
    .string()
    .trim()
    .max(140, 'No máximo 140 caracteres.')
    .refine((v) => v === '' || SLUG_RE.test(v), 'Use letras minúsculas, números e hífens.')
    .refine((v) => !RESERVED_SLUGS.includes(v), 'Este endereço é reservado pelo sistema.'),
  parent_id: z.number().nullable(),
  description_html: z.string().max(20000, 'No máximo 20.000 caracteres.'),
  meta_title: z.string().max(120, 'No máximo 120 caracteres.'),
  meta_description: z.string().max(320, 'No máximo 320 caracteres.'),
  is_active: z.boolean(),
});
export type CategoryForm = z.infer<typeof categorySchema>;
