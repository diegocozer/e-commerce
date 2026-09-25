import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrf, request, type Envelope } from '@/shared/api/client';
import type { AdminMe } from '@/shared/api/types';
import { meKey } from '@/shared/auth';

export interface LoginInput {
  email: string;
  password: string;
}

export async function login(input: LoginInput): Promise<AdminMe> {
  await ensureCsrf(true);
  const res = await request<Envelope<AdminMe>>('POST', '/admin/auth/login', { body: input });
  return res.data;
}

export function useLogin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: login,
    onSuccess: (me) => {
      qc.clear();
      qc.setQueryData(meKey, me);
    },
  });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.post<void>('/admin/auth/logout'),
    onSettled: () => {
      qc.clear();
      qc.setQueryData(meKey, null);
    },
  });
}

export const forgotPassword = (email: string) => api.post<Envelope<{ message: string }>>('/admin/auth/forgot-password', { email });
export const resetPassword = (body: { token: string; email: string; password: string; password_confirmation: string }) =>
  api.post<Envelope<{ message: string }>>('/admin/auth/reset-password', body);
export const changePassword = (body: { current_password: string; password: string; password_confirmation: string }) =>
  api.put<void>('/admin/me/password', body);
