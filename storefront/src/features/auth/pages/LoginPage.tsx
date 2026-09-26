import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import Link from '@mui/material/Link';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router';
import { toApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { AuthCard } from '../components/AuthCard';
import { safeRedirect } from '../components/Guards';
import { PasswordField } from '../components/PasswordField';
import { useLogin } from '../hooks/useAuth';
import { loginSchema, type LoginValues } from '../schemas';

export default function LoginPage() {
  const [params] = useSearchParams();
  const redirect = safeRedirect(params.get('redirect'));
  const navigate = useNavigate();
  const notify = useSnackbar();
  const login = useLogin();
  const [alert, setAlert] = useState<string | null>(null);
  const [lockSeconds, setLockSeconds] = useState(0);
  const { control, handleSubmit, setError, formState } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
    mode: 'onBlur',
    reValidateMode: 'onChange',
  });

  useEffect(() => {
    if (lockSeconds <= 0) return;
    const id = setTimeout(() => setLockSeconds((s) => s - 1), 1000);
    return () => clearTimeout(id);
  }, [lockSeconds]);

  const onSubmit = handleSubmit(async (values) => {
    setAlert(null);
    try {
      const res = await login.mutateAsync({ email: values.email.trim().toLowerCase(), password: values.password, remember: values.remember });
      const first = res.customer.name.split(' ')[0];
      notify(res.cart_merge?.merged ? `Olá, ${first}! Juntamos os itens do seu carrinho.` : `Olá, ${first}!`, { severity: 'success' });
      navigate(redirect, { replace: true });
    } catch (err) {
      const e = toApiError(err);
      if (e.isValidation) {
        const general = e.fieldErrors.email?.[0] === 'E-mail ou senha inválidos.' || !Object.keys(e.fieldErrors).length;
        if (general) setAlert(e.fieldErrors.email?.[0] ?? e.message);
        else setAlert(applyServerErrors(err, setError, ['email', 'password']).join(' ') || null);
      } else if (e.code === 'too_many_requests') {
        setLockSeconds(e.retryAfter ?? 60);
        setAlert('Muitas tentativas. Tente novamente em 1 minuto.');
      } else setAlert(e.message);
    }
  });

  return (
    <AuthCard>
      <Seo title="Entrar" robots="noindex,nofollow" />
      <PageHeading sx={{ fontSize: 28 }}>Entrar</PageHeading>
      {alert ? (
        <Alert severity="error" role="alert" sx={{ mb: 4 }}>
          {alert}
        </Alert>
      ) : null}
      <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <Controller
          name="email"
          control={control}
          render={({ field, fieldState }) => (
            <TextField {...field} label="E-mail" type="email" required error={Boolean(fieldState.error)} helperText={fieldState.error?.message} slotProps={{ htmlInput: { autoComplete: 'email' } }} />
          )}
        />
        <Controller
          name="password"
          control={control}
          render={({ field, fieldState }) => (
            <PasswordField {...field} label="Senha" required autoComplete="current-password" error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />
          )}
        />
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Controller name="remember" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="Manter conectado" />} />
          <Link component={RouterLink} to="/recuperar-senha">Esqueci minha senha</Link>
        </Box>
        <Button type="submit" loading={formState.isSubmitting} disabled={lockSeconds > 0}>
          {formState.isSubmitting ? 'Entrando…' : lockSeconds > 0 ? `Aguarde ${lockSeconds} s` : 'Entrar'}
        </Button>
      </Box>
      <Divider sx={{ my: 6 }}>ou</Divider>
      <Typography sx={{ textAlign: 'center' }}>
        Novo por aqui?{' '}
        <Button component={RouterLink} to={`/cadastro${params.get('redirect') ? `?redirect=${encodeURIComponent(redirect)}` : ''}`} variant="outlined" size="medium">
          Criar conta
        </Button>
      </Typography>
    </AuthCard>
  );
}
