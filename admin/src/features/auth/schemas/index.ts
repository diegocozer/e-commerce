import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().trim().min(1, 'Informe seu e-mail.').pipe(z.email('Informe um e-mail válido.')),
  password: z.string().min(1, 'Informe sua senha.'),
});
export type LoginForm = z.infer<typeof loginSchema>;

/** Política de senha do painel (API §3.G.1): min 12, maiúsc./minúsc., número, símbolo. */
export const strongPassword = z
  .string()
  .min(12, 'A senha deve ter pelo menos 12 caracteres.')
  .regex(/[a-z]/, 'Inclua uma letra minúscula.')
  .regex(/[A-Z]/, 'Inclua uma letra maiúscula.')
  .regex(/\d/, 'Inclua um número.')
  .regex(/[^A-Za-z0-9]/, 'Inclua um símbolo.');

export const resetSchema = z
  .object({ password: strongPassword, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não conferem.' });
export type ResetForm = z.infer<typeof resetSchema>;

export const changePasswordSchema = z
  .object({ current_password: z.string().min(1, 'Informe a senha atual.'), password: strongPassword, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não conferem.' });
export type ChangePasswordForm = z.infer<typeof changePasswordSchema>;
