import { z } from 'zod';
import { isValidCEP, onlyDigits } from '@/shared/formatters/postalCode';

// UX §4.6.2 — mensagens; regras espelham API §3.D (endereços).
export const addressSchema = z
  .object({
    postal_code: z.string().refine(isValidCEP, 'Informe um CEP válido (8 dígitos)'),
    street: z.string().trim().min(1, 'Informe a rua').max(200),
    number: z.string().trim().max(20),
    no_number: z.boolean(),
    complement: z.string().trim().max(100),
    district: z.string().trim().min(1, 'Informe o bairro').max(100),
    city: z.string(),
    state: z.string(),
    reference: z.string().trim().max(200),
    recipient_name: z.string().trim().min(3, 'Informe quem vai receber').max(150, 'Informe quem vai receber'),
    phone: z.string().refine((v) => [10, 11].includes(onlyDigits(v).length), 'Informe um telefone com DDD'),
    label: z.string().trim().max(50),
    is_default: z.boolean(),
  })
  .refine((v) => v.no_number || v.number.trim().length > 0, { path: ['number'], message: "Informe o número ou marque 'Sem número'" });

export type AddressFormValues = z.infer<typeof addressSchema>;
