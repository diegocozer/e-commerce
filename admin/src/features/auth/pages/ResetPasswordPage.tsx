import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, Link, Stack } from '@mui/material';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link as RouterLink, useSearchParams } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import { usePageTitle } from '@/shared/hooks/usePageTitle';
import { applyServerErrors, RHFTextField } from '@/shared/ui/form';
import { resetPassword } from '../api';
import { resetSchema, type ResetForm } from '../schemas';
import { AuthCard } from './AuthCard';

export default function ResetPasswordPage() {
  usePageTitle('Redefinir senha');
  const [sp] = useSearchParams();
  const [done, setDone] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const { control, handleSubmit, setError, formState } = useForm<ResetForm>({ resolver: zodResolver(resetSchema), defaultValues: { password: '', password_confirmation: '' } });
  const onSubmit = handleSubmit(async (v) => {
    setErr(null);
    try {
      const res = await resetPassword({ token: sp.get('token') ?? '', email: sp.get('email') ?? '', ...v });
      setDone(res.data.message);
    } catch (e) {
      const rest = applyServerErrors(e, setError, { knownFields: (k) => k === 'password' || k === 'password_confirmation' });
      setErr(rest.length ? rest.join(' ') : errorMessage(e));
    }
  });
  return (
    <AuthCard title="Redefinir senha">
      {done ? (
        <Stack spacing={4}>
          <Alert severity="success">{done}</Alert>
          <Button component={RouterLink} to="/entrar" variant="contained">
            Entrar
          </Button>
        </Stack>
      ) : (
        <Stack component="form" noValidate spacing={4} onSubmit={onSubmit}>
          {err && <Alert severity="error">{err}</Alert>}
          <RHFTextField control={control} name="password" label="Nova senha" type="password" autoComplete="new-password" required helperText="Mínimo 12 caracteres, com maiúscula, minúscula, número e símbolo." />
          <RHFTextField control={control} name="password_confirmation" label="Confirme a nova senha" type="password" autoComplete="new-password" required />
          <Button type="submit" variant="contained" disabled={formState.isSubmitting}>
            Redefinir senha
          </Button>
          <Link component={RouterLink} to="/entrar" sx={{ textAlign: 'center' }}>
            Voltar para o login
          </Link>
        </Stack>
      )}
    </AuthCard>
  );
}
