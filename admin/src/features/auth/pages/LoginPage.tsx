import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, CircularProgress, Link, Stack } from '@mui/material';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link as RouterLink, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { isApiError } from '@/shared/api/errors';
import { useMe } from '@/shared/auth';
import { usePageTitle } from '@/shared/hooks/usePageTitle';
import { RHFTextField } from '@/shared/ui/form';
import { useLogin } from '../api';
import { loginSchema, type LoginForm } from '../schemas';
import { AuthCard } from './AuthCard';

/** Só aceita redirecionamento interno (evita open redirect). */
export function safeRedirect(value: string | null): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/entrar')) return '/';
  return value;
}

export default function LoginPage() {
  usePageTitle('Entrar');
  const [sp] = useSearchParams();
  const navigate = useNavigate();
  const { data: me } = useMe();
  const loginM = useLogin();
  const [formError, setFormError] = useState<string | null>(null);
  const expired = sp.get('expirada') === '1';
  const { control, handleSubmit, setError } = useForm<LoginForm>({ resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '' }, mode: 'onBlur' });

  if (me) return <Navigate to={safeRedirect(sp.get('redirect'))} replace />;

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await loginM.mutateAsync(values);
      navigate(safeRedirect(sp.get('redirect')), { replace: true });
    } catch (e) {
      if (!isApiError(e)) return setFormError('Não foi possível entrar. Tente novamente.');
      if (e.status === 422) {
        const fe = e.fieldErrors ?? {};
        const emailMsg = fe.email?.[0];
        const pwdMsg = fe.password?.[0];
        if (emailMsg && /senha/i.test(emailMsg) && !pwdMsg) {
          // "E-mail ou senha inválidos." vem em errors.email: mostramos no topo, sem culpar um campo só.
          setFormError('E-mail ou senha incorretos.');
        } else {
          if (emailMsg) setError('email', { type: 'server', message: emailMsg });
          if (pwdMsg) setError('password', { type: 'server', message: pwdMsg });
          if (!emailMsg && !pwdMsg) setFormError(e.message);
        }
      } else if (e.status === 429) setFormError('Muitas tentativas. Aguarde 1 minuto.');
      else if (e.code === 'account_disabled') setFormError('Acesso desativado. Procure o administrador.');
      else setFormError('Não foi possível entrar. Tente novamente.');
    }
  });

  return (
    <AuthCard title="Entrar no painel">
      <Stack component="form" noValidate onSubmit={onSubmit} spacing={4}>
        {expired && !formError && <Alert severity="info">Sua sessão expirou. Entre novamente para continuar.</Alert>}
        {formError && (
          <Alert severity="error" role="alert">
            {formError}
          </Alert>
        )}
        <RHFTextField control={control} name="email" label="E-mail" type="email" autoComplete="username" required autoFocus />
        <RHFTextField control={control} name="password" label="Senha" type="password" autoComplete="current-password" required />
        <Button type="submit" variant="contained" size="large" disabled={loginM.isPending} startIcon={loginM.isPending ? <CircularProgress size={16} color="inherit" /> : undefined}>
          Entrar
        </Button>
        <Link component={RouterLink} to="/recuperar-senha" sx={{ textAlign: 'center' }}>
          Esqueci minha senha
        </Link>
      </Stack>
    </AuthCard>
  );
}
