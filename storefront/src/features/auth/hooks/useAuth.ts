import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { clearCartToken } from '@/shared/api/cartToken';
import type { Customer } from '@/shared/api/types';
import { authKeys, fetchMe, login, logout, register, type AuthResult, type LoginInput, type RegisterInput } from '../api';

export function useAuth() {
  const q = useQuery({ queryKey: authKeys.me, queryFn: fetchMe, retry: false, staleTime: 60_000 });
  return { customer: q.data ?? null, isLoading: q.isLoading, isAuthenticated: Boolean(q.data), query: q };
}

/** Após login/cadastro: apaga token do visitante (merge feito no backend), limpa cache e hidrata o /me. */
function useAfterAuth() {
  const qc = useQueryClient();
  return (result: AuthResult) => {
    clearCartToken();
    qc.clear();
    qc.setQueryData<Customer | null>(authKeys.me, result.customer);
  };
}

export function useLogin() {
  const after = useAfterAuth();
  return useMutation({ mutationFn: (input: LoginInput) => login(input), onSuccess: after });
}

export function useRegister() {
  const after = useAfterAuth();
  return useMutation({ mutationFn: (input: RegisterInput) => register(input), onSuccess: after });
}

export function useLogout() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: logout,
    onSettled: () => {
      qc.clear();
      qc.setQueryData(authKeys.me, null);
    },
  });
}
