import { getData, postData, send } from '@/shared/api/client';
import { ApiError, toApiError } from '@/shared/api/errors';
import { refreshCsrfCookie } from '@/shared/api/csrf';
import type { CartMergeReport, Customer } from '@/shared/api/types';

export const authKeys = { me: ['auth', 'me'] as const };

/** GET /me — 401 ⇒ visitante (null). */
export async function fetchMe(): Promise<Customer | null> {
  try {
    return await getData<Customer>('/me');
  } catch (err) {
    const e = toApiError(err);
    if (e.status === 401) return null;
    throw e;
  }
}

export interface AuthResult {
  customer: Customer;
  cart_merge: CartMergeReport | null;
}

export interface LoginInput {
  email: string;
  password: string;
  remember?: boolean;
}

export async function login(input: LoginInput): Promise<AuthResult> {
  await refreshCsrfCookie().catch(() => undefined);
  return postData<AuthResult>('/auth/login', input);
}

export interface RegisterInput {
  type: 'individual' | 'company';
  name: string;
  cpf?: string | null;
  email: string;
  phone: string;
  password: string;
  password_confirmation: string;
  accept_terms: boolean;
  terms_version: string;
  marketing_opt_in: boolean;
  company?: {
    cnpj: string;
    legal_name: string;
    trade_name?: string | null;
    state_registration?: string | null;
    state_registration_exempt: boolean;
  };
}

export async function register(input: RegisterInput): Promise<AuthResult> {
  await refreshCsrfCookie().catch(() => undefined);
  return postData<AuthResult>('/auth/register', input);
}

export async function logout(): Promise<void> {
  try {
    await send('post', '/auth/logout');
  } catch (err) {
    if (!(err instanceof ApiError && err.status === 401)) throw err;
  }
}

export function forgotPassword(email: string): Promise<{ message: string }> {
  return postData('/auth/forgot-password', { email });
}

export function resetPassword(input: { token: string; email: string; password: string; password_confirmation: string }): Promise<{ message: string }> {
  return postData('/auth/reset-password', input);
}

export function verifyEmail(input: { uuid: string; hash: string; expires: string; signature: string }): Promise<{ verified: boolean }> {
  return postData('/auth/email/verify', input);
}
