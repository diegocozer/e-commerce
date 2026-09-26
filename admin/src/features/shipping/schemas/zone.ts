import { z } from 'zod';
import { onlyDigits } from '@/shared/formatters/document';

export const zoneSchema = z
  .object({
    name: z.string().trim().min(1, 'Informe o nome.').max(120, 'No máximo 120 caracteres.'),
    description: z.string().max(255, 'No máximo 255 caracteres.'),
    is_active: z.boolean(),
    postal_ranges: z.array(z.object({ start: z.string(), end: z.string() })),
    cities: z.array(z.object({ city_ibge_code: z.string(), city_name: z.string(), state: z.string() })),
    states: z.array(z.string()),
  })
  .superRefine((v, ctx) => {
    const ranges = v.postal_ranges.map((r) => ({ s: onlyDigits(r.start), e: onlyDigits(r.end) }));
    ranges.forEach((r, i) => {
      if (r.s.length !== 8) ctx.addIssue({ code: 'custom', path: ['postal_ranges', i, 'start'], message: 'CEP inválido.' });
      if (r.e.length !== 8) ctx.addIssue({ code: 'custom', path: ['postal_ranges', i, 'end'], message: 'CEP inválido.' });
      if (r.s.length === 8 && r.e.length === 8 && r.s > r.e) ctx.addIssue({ code: 'custom', path: ['postal_ranges', i, 'end'], message: 'O CEP final deve ser ≥ inicial.' });
      ranges.forEach((o, j) => {
        if (j < i && r.s.length === 8 && o.s.length === 8 && r.s <= o.e && o.s <= r.e)
          ctx.addIssue({ code: 'custom', path: ['postal_ranges', i, 'start'], message: `Sobrepõe a faixa ${j + 1} desta zona.` });
      });
    });
    if (ranges.length + v.cities.length + v.states.length === 0) ctx.addIssue({ code: 'custom', path: ['postal_ranges'], message: 'Adicione ao menos uma faixa de CEP, cidade ou estado.' });
  });
export type ZoneForm = z.infer<typeof zoneSchema>;
