import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import Link from '@mui/material/Link';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, type Path } from 'react-hook-form';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router';
import { useSettings } from '@/features/catalog/hooks/queries';
import { toApiError } from '@/shared/api/errors';
import { formatCNPJ, formatCPF, normalizeCNPJ } from '@/shared/formatters/document';
import { formatPhone } from '@/shared/formatters/phone';
import { onlyDigits } from '@/shared/formatters/postalCode';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { useSnackbar } from '@/shared/ui/Snackbar';
import type { RegisterInput } from '../api';
import { AuthCard } from '../components/AuthCard';
import { safeRedirect } from '../components/Guards';
import { PasswordField } from '../components/PasswordField';
import { useRegister } from '../hooks/useAuth';
import { passwordStrength, registerSchema, type RegisterValues } from '../schemas';

const FIELDS = [
  'name', 'cpf', 'phone', 'email', 'password', 'password_confirmation', 'accept_terms', 'marketing_opt_in',
  'company.cnpj', 'company.legal_name', 'company.trade_name', 'company.state_registration', 'company.state_registration_exempt',
] as const;

export default function RegisterPage() {
  const [params] = useSearchParams();
  const redirect = safeRedirect(params.get('redirect'), '/conta');
  const navigate = useNavigate();
  const notify = useSnackbar();
  const settings = useSettings();
  const register = useRegister();
  const [top, setTop] = useState<string[]>([]);
  const [duplicate, setDuplicate] = useState<string | null>(null);
  const { control, handleSubmit, setError, watch, setValue, formState } = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    mode: 'onBlur',
    reValidateMode: 'onChange',
    defaultValues: {
      type: 'company',
      name: '', cpf: '', phone: '', email: '', password: '', password_confirmation: '',
      marketing_opt_in: false, accept_terms: false,
      company: { cnpj: '', legal_name: '', trade_name: '', state_registration: '', state_registration_exempt: false },
    },
  });
  const type = watch('type');
  const exempt = watch('company.state_registration_exempt');
  const password = watch('password');
  const strength = passwordStrength(password);

  const onSubmit = handleSubmit(async (v) => {
    setTop([]);
    setDuplicate(null);
    const ie = v.company.state_registration.trim();
    const isentoText = ie.toUpperCase() === 'ISENTO';
    const input: RegisterInput = {
      type: v.type,
      name: v.name.trim(),
      cpf: onlyDigits(v.cpf) || null,
      email: v.email.trim().toLowerCase(),
      phone: onlyDigits(v.phone),
      password: v.password,
      password_confirmation: v.password_confirmation,
      accept_terms: true,
      terms_version: settings.data?.terms_version ?? '',
      marketing_opt_in: v.marketing_opt_in,
      ...(v.type === 'company'
        ? {
            company: {
              cnpj: normalizeCNPJ(v.company.cnpj),
              legal_name: v.company.legal_name.trim(),
              trade_name: v.company.trade_name.trim() || null,
              state_registration: v.company.state_registration_exempt || isentoText ? null : normalizeCNPJ(ie),
              state_registration_exempt: v.company.state_registration_exempt || isentoText,
            },
          }
        : {}),
    };
    if (v.type === 'individual') delete input.company;
    try {
      const res = await register.mutateAsync(input);
      notify(`Conta criada! Bem-vindo(a), ${res.customer.name.split(' ')[0]}.`, { severity: 'success' });
      navigate(redirect, { replace: true });
    } catch (err) {
      const e = toApiError(err);
      const dup = ['email', 'cpf', 'company.cnpj'].find((k) => e.fieldErrors[k]?.[0]?.startsWith('Já existe'));
      if (dup) setDuplicate(e.fieldErrors[dup][0]);
      if (e.fieldErrors.terms_version) {
        void settings.refetch();
        setTop(['Os termos de uso foram atualizados. Revise e aceite novamente.']);
        return;
      }
      setTop(applyServerErrors(err, setError, FIELDS));
    }
  });

  const text = (name: Path<RegisterValues>, label: string, opts: { required?: boolean; mask?: (v: string) => string; autoComplete?: string; inputMode?: 'numeric' | 'tel' | 'email' | 'text'; helper?: string; disabled?: boolean; type?: string } = {}) => (
    <Controller
      name={name}
      control={control}
      render={({ field, fieldState }) => (
        <TextField
          {...field}
          value={field.value as string}
          onChange={(e) => field.onChange(opts.mask ? opts.mask(e.target.value) : e.target.value)}
          label={label}
          type={opts.type}
          required={opts.required}
          disabled={opts.disabled}
          error={Boolean(fieldState.error)}
          helperText={fieldState.error?.message ?? opts.helper}
          slotProps={{ htmlInput: { autoComplete: opts.autoComplete, inputMode: opts.inputMode } }}
        />
      )}
    />
  );
  const section = (t: string) => (
    <Typography variant="overline" component="h2" sx={{ mt: 2 }}>
      {t}
    </Typography>
  );

  return (
    <AuthCard width={560}>
      <Seo title="Criar conta" robots="noindex,nofollow" />
      <PageHeading sx={{ fontSize: 28 }}>Criar conta</PageHeading>
      <Controller
        name="type"
        control={control}
        render={({ field }) => (
          <ToggleButtonGroup exclusive fullWidth value={field.value} onChange={(_, v: 'individual' | 'company' | null) => v && field.onChange(v)} aria-label="Tipo de cadastro" sx={{ mb: 4 }}>
            <ToggleButton value="individual" sx={{ textTransform: 'none', minHeight: 48 }}>Pessoa física</ToggleButton>
            <ToggleButton value="company" sx={{ textTransform: 'none', minHeight: 48 }}>Empresa (CNPJ)</ToggleButton>
          </ToggleButtonGroup>
        )}
      />
      {duplicate ? (
        <Alert severity="warning" sx={{ mb: 3 }}>
          {duplicate} <Link component={RouterLink} to="/entrar">Entrar</Link> ou <Link component={RouterLink} to="/recuperar-senha">Recuperar senha</Link>
        </Alert>
      ) : null}
      {top.length ? (
        <Alert severity="error" role="alert" sx={{ mb: 3 }}>
          {top.map((m) => <div key={m}>{m}</div>)}
        </Alert>
      ) : null}
      <Typography variant="caption" color="text.secondary">* obrigatório</Typography>
      <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 3, mt: 2 }}>
        {type === 'company' ? (
          <>
            {section('Dados da empresa')}
            {text('company.cnpj', 'CNPJ', { required: true, mask: formatCNPJ, helper: 'Aceita CNPJ alfanumérico' })}
            {text('company.legal_name', 'Razão social', { required: true, autoComplete: 'organization' })}
            {text('company.trade_name', 'Nome fantasia')}
            <Box sx={{ display: 'flex', gap: 2, alignItems: 'flex-start' }}>
              {text('company.state_registration', 'Inscrição estadual', { required: !exempt, disabled: exempt })}
              <Controller
                name="company.state_registration_exempt"
                control={control}
                render={({ field }) => (
                  <FormControlLabel
                    sx={{ mt: 2, whiteSpace: 'nowrap' }}
                    control={<Checkbox checked={field.value} onChange={(e) => { field.onChange(e.target.checked); if (e.target.checked) setValue('company.state_registration', ''); }} />}
                    label="Isento"
                  />
                )}
              />
            </Box>
            {section('Responsável')}
          </>
        ) : (
          section('Seus dados')
        )}
        {text('name', 'Nome completo', { required: true, autoComplete: 'name' })}
        {text('cpf', type === 'company' ? 'CPF do responsável (opcional)' : 'CPF', { required: type === 'individual', mask: formatCPF, inputMode: 'numeric' })}
        {text('phone', 'Telefone/WhatsApp', { required: true, mask: formatPhone, inputMode: 'tel', autoComplete: 'tel' })}
        {section('Acesso')}
        {text('email', 'E-mail', { required: true, type: 'email', autoComplete: 'email', inputMode: 'email' })}
        <Controller
          name="password"
          control={control}
          render={({ field, fieldState }) => (
            <PasswordField {...field} label="Senha" required autoComplete="new-password" error={Boolean(fieldState.error)} helperText={fieldState.error?.message ?? 'Mínimo 8 caracteres, com letras e números'} />
          )}
        />
        {password ? (
          <Typography variant="caption" aria-live="polite">
            Força da senha: {strength.label}
          </Typography>
        ) : null}
        <Controller
          name="password_confirmation"
          control={control}
          render={({ field, fieldState }) => (
            <PasswordField {...field} label="Confirmar senha" required autoComplete="new-password" error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />
          )}
        />
        <Controller name="marketing_opt_in" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="Quero receber ofertas por e-mail/WhatsApp (opcional)" />} />
        <Controller
          name="accept_terms"
          control={control}
          render={({ field, fieldState }) => (
            <Box>
              <FormControlLabel
                control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />}
                label={
                  <>
                    Li e aceito os <Link component={RouterLink} to="/institucional/termos" target="_blank">Termos de uso</Link> e a{' '}
                    <Link component={RouterLink} to="/institucional/privacidade" target="_blank">Política de privacidade</Link>*
                  </>
                }
              />
              {fieldState.error ? <FormHelperText error>{fieldState.error.message}</FormHelperText> : null}
            </Box>
          )}
        />
        <Button type="submit" loading={formState.isSubmitting}>Criar conta</Button>
        <Typography sx={{ textAlign: 'center' }}>
          Já tem conta? <Link component={RouterLink} to="/entrar">Entrar</Link>
        </Typography>
      </Box>
    </AuthCard>
  );
}
