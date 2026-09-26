import { z } from 'zod';
import { isValidCNPJ, isValidCPF, normalizeCNPJ } from '@/shared/formatters/document';
import { onlyDigits } from '@/shared/formatters/postalCode';

export const loginSchema = z.object({
  email: z.string().trim().min(1, 'Informe seu e-mail').email('E-mail inválido'),
  password: z.string().min(1, 'Informe sua senha').max(72),
  remember: z.boolean(),
});
export type LoginValues = z.infer<typeof loginSchema>;

// API §3.C register: 8–72, letras e números, não contém o e-mail.
export const passwordRule = z
  .string()
  .min(8, 'Mínimo 8 caracteres, com letras e números')
  .max(72, 'Máximo 72 caracteres')
  .refine((v) => /[A-Za-z]/.test(v) && /\d/.test(v), 'Use letras e números');

export function passwordStrength(pw: string): { score: 0 | 1 | 2 | 3 | 4; label: string } {
  let s = 0;
  if (pw.length >= 8) s++;
  if (pw.length >= 12) s++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
  if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) s++;
  const labels = ['Muito fraca', 'Fraca', 'Razoável', 'Boa', 'Forte'];
  return { score: s as 0 | 1 | 2 | 3 | 4, label: labels[s] };
}

export const registerSchema = z
  .object({
    type: z.enum(['individual', 'company']),
    name: z.string().trim().min(3, 'Informe seu nome completo').max(120),
    cpf: z.string(),
    phone: z.string().refine((v) => [10, 11].includes(onlyDigits(v).length), 'Informe um telefone com DDD'),
    email: z.string().trim().min(1, 'Informe seu e-mail').email('E-mail inválido').max(191),
    password: passwordRule,
    password_confirmation: z.string(),
    marketing_opt_in: z.boolean(),
    accept_terms: z.boolean(),
    company: z.object({
      cnpj: z.string(),
      legal_name: z.string().trim(),
      trade_name: z.string().trim().max(150),
      state_registration: z.string().trim(),
      state_registration_exempt: z.boolean(),
    }),
  })
  .superRefine((v, ctx) => {
    const add = (path: (string | number)[], message: string) => ctx.addIssue({ code: z.ZodIssueCode.custom, path, message });
    if (v.password !== v.password_confirmation) add(['password_confirmation'], 'As senhas não coincidem');
    if (v.email && v.password && v.password.toLowerCase().includes(v.email.toLowerCase())) add(['password'], 'A senha não pode conter o e-mail');
    if (!v.accept_terms) add(['accept_terms'], 'Aceite os termos para continuar');
    if (v.type === 'individual') {
      if (v.name.trim().split(/\s+/).length < 2) add(['name'], 'Informe nome e sobrenome');
      if (!isValidCPF(v.cpf)) add(['cpf'], 'CPF inválido');
    } else {
      if (onlyDigits(v.cpf) && !isValidCPF(v.cpf)) add(['cpf'], 'CPF inválido');
      if (!isValidCNPJ(v.company.cnpj)) add(['company', 'cnpj'], 'CNPJ inválido');
      if (v.company.legal_name.length < 3) add(['company', 'legal_name'], 'Informe a razão social');
      if (!v.company.state_registration_exempt) {
        const ie = v.company.state_registration.toUpperCase();
        if (ie === 'ISENTO') return;
        if (!/^[0-9A-Za-z.\-/]{2,20}$/.test(ie) || normalizeCNPJ(ie).length < 2 || normalizeCNPJ(ie).length > 14) {
          add(['company', 'state_registration'], 'Informe a inscrição estadual ou marque Isento');
        }
      }
    }
  });
export type RegisterValues = z.infer<typeof registerSchema>;

export const resetSchema = z
  .object({ password: passwordRule, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não coincidem' });
export type ResetValues = z.infer<typeof resetSchema>;
