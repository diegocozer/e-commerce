import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router';
import { toApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { resetPassword } from '../api';
import { AuthCard } from '../components/AuthCard';
import { PasswordField } from '../components/PasswordField';
import { resetSchema, type ResetValues } from '../schemas';

export default function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const navigate = useNavigate();
  const notify = useSnackbar();
  const [expired, setExpired] = useState(!token || !email);
  const [alert, setAlert] = useState<string | null>(null);
  const { control, handleSubmit, setError, formState } = useForm<ResetValues>({ resolver: zodResolver(resetSchema), defaultValues: { password: '', password_confirmation: '' }, mode: 'onBlur' });

  const onSubmit = handleSubmit(async (v) => {
    setAlert(null);
    try {
      await resetPassword({ token, email, ...v });
      notify('Senha alterada', { severity: 'success' });
      navigate('/entrar', { replace: true });
    } catch (err) {
      const e = toApiError(err);
      if (e.fieldErrors.token || e.fieldErrors.email) setExpired(true);
      else setAlert(applyServerErrors(err, setError, ['password', 'password_confirmation']).join(' ') || null);
    }
  });

  return (
    <AuthCard>
      <Seo title="Redefinir senha" robots="noindex,nofollow" />
      <PageHeading sx={{ fontSize: 28 }}>Redefinir senha</PageHeading>
      {expired ? (
        <Alert severity="error">
          Este link expirou.{' '}
          <Link component={RouterLink} to="/recuperar-senha">Solicitar novo link</Link>
        </Alert>
      ) : (
        <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {alert ? <Alert severity="error">{alert}</Alert> : null}
          <Controller name="password" control={control} render={({ field, fieldState }) => <PasswordField {...field} label="Nova senha" required autoComplete="new-password" error={Boolean(fieldState.error)} helperText={fieldState.error?.message ?? 'Mínimo 8 caracteres, com letras e números'} />} />
          <Controller name="password_confirmation" control={control} render={({ field, fieldState }) => <PasswordField {...field} label="Confirmar senha" required autoComplete="new-password" error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />} />
          <Button type="submit" loading={formState.isSubmitting}>Salvar nova senha</Button>
        </Box>
      )}
    </AuthCard>
  );
}
