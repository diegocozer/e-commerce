import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { authKeys, useAuth } from '@/features/auth';
import { useSettings } from '@/features/catalog/hooks/queries';
import type { Customer } from '@/shared/api/types';
import { formatCNPJ, formatCPF, isValidCPF, normalizeCNPJ } from '@/shared/formatters/document';
import { formatPhone } from '@/shared/formatters/phone';
import { onlyDigits } from '@/shared/formatters/postalCode';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { updateCompany, updateProfile } from '../api';

const profileSchema = z.object({
  name: z.string().trim().min(3, 'Informe seu nome').max(120),
  phone: z.string().refine((v) => [10, 11].includes(onlyDigits(v).length), 'Informe um telefone com DDD'),
  cpf: z.string().refine((v) => !onlyDigits(v) || isValidCPF(v), 'CPF inválido'),
  marketing_opt_in: z.boolean(),
});
type ProfileValues = z.infer<typeof profileSchema>;

const companySchema = z
  .object({ legal_name: z.string().trim().min(3, 'Informe a razão social').max(150), trade_name: z.string().trim().max(150), state_registration: z.string().trim(), state_registration_exempt: z.boolean() })
  .refine((v) => v.state_registration_exempt || /^[0-9A-Za-z.\-/]{2,20}$/.test(v.state_registration), { path: ['state_registration'], message: 'Informe a inscrição estadual ou marque Isento' });
type CompanyValues = z.infer<typeof companySchema>;

function useDirtyGuard(dirty: boolean) {
  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);
}

function ProfileForm({ customer }: { customer: Customer }) {
  const qc = useQueryClient();
  const notify = useSnackbar();
  const [top, setTop] = useState<string[]>([]);
  const defaults: ProfileValues = { name: customer.name, phone: formatPhone(customer.phone), cpf: formatCPF(customer.cpf), marketing_opt_in: customer.marketing_opt_in };
  const { control, handleSubmit, setError, reset, formState } = useForm<ProfileValues>({ resolver: zodResolver(profileSchema), defaultValues: defaults, mode: 'onBlur' });
  useDirtyGuard(formState.isDirty);
  const cpfLocked = Boolean(customer.cpf);
  const onSubmit = handleSubmit(async (v) => {
    setTop([]);
    try {
      const updated = await updateProfile({ name: v.name.trim(), phone: onlyDigits(v.phone), marketing_opt_in: v.marketing_opt_in, ...(!cpfLocked && onlyDigits(v.cpf) ? { cpf: onlyDigits(v.cpf) } : {}) });
      qc.setQueryData(authKeys.me, updated);
      reset({ name: updated.name, phone: formatPhone(updated.phone), cpf: formatCPF(updated.cpf), marketing_opt_in: updated.marketing_opt_in });
      notify('Dados salvos', { severity: 'success' });
    } catch (err) {
      setTop(applyServerErrors(err, setError, ['name', 'phone', 'cpf', 'marketing_opt_in']));
    }
  });
  return (
    <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <Typography variant="h4" component="h2">{customer.type === 'company' ? 'Responsável' : 'Seus dados'}</Typography>
      {top.length ? <Alert severity="error">{top.join(' ')}</Alert> : null}
      <Controller name="name" control={control} render={({ field, fieldState }) => <TextField {...field} label="Nome completo" required error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />} />
      <Controller
        name="cpf"
        control={control}
        render={({ field, fieldState }) => (
          <TextField {...field} onChange={(e) => field.onChange(formatCPF(e.target.value))} label={customer.type === 'company' ? 'CPF do responsável' : 'CPF'} disabled={cpfLocked} error={Boolean(fieldState.error)} helperText={fieldState.error?.message ?? (cpfLocked ? 'Para alterar, fale conosco' : undefined)} />
        )}
      />
      <Controller name="phone" control={control} render={({ field, fieldState }) => <TextField {...field} onChange={(e) => field.onChange(formatPhone(e.target.value))} label="Telefone" required error={Boolean(fieldState.error)} helperText={fieldState.error?.message} slotProps={{ htmlInput: { inputMode: 'tel', autoComplete: 'tel' } }} />} />
      <TextField label="E-mail" value={customer.email} disabled helperText="Para alterar o e-mail, fale conosco." />
      <Controller name="marketing_opt_in" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="Quero receber ofertas por e-mail/WhatsApp" />} />
      <Button type="submit" disabled={!formState.isDirty} loading={formState.isSubmitting} sx={{ alignSelf: 'flex-start' }}>Salvar alterações</Button>
    </Box>
  );
}

function CompanyForm({ customer }: { customer: Customer }) {
  const company = customer.company!;
  const qc = useQueryClient();
  const notify = useSnackbar();
  const [top, setTop] = useState<string[]>([]);
  const { control, handleSubmit, setError, reset, watch, setValue, formState } = useForm<CompanyValues>({
    resolver: zodResolver(companySchema),
    defaultValues: { legal_name: company.legal_name, trade_name: company.trade_name ?? '', state_registration: company.state_registration ?? '', state_registration_exempt: company.state_registration_exempt },
    mode: 'onBlur',
  });
  useDirtyGuard(formState.isDirty);
  const exempt = watch('state_registration_exempt');
  const onSubmit = handleSubmit(async (v) => {
    setTop([]);
    try {
      const updated = await updateCompany({ legal_name: v.legal_name.trim(), trade_name: v.trade_name.trim() || null, state_registration: v.state_registration_exempt ? null : normalizeCNPJ(v.state_registration), state_registration_exempt: v.state_registration_exempt });
      qc.setQueryData(authKeys.me, updated);
      reset(v);
      notify('Dados da empresa salvos', { severity: 'success' });
    } catch (err) {
      setTop(applyServerErrors(err, setError, ['legal_name', 'trade_name', 'state_registration', 'state_registration_exempt']));
    }
  });
  return (
    <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <Typography variant="h4" component="h2">Empresa</Typography>
      {top.length ? <Alert severity="error">{top.join(' ')}</Alert> : null}
      <TextField label="CNPJ" value={formatCNPJ(company.cnpj)} disabled helperText="Para alterar, fale conosco" />
      <Controller name="legal_name" control={control} render={({ field, fieldState }) => <TextField {...field} label="Razão social" required error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />} />
      <Controller name="trade_name" control={control} render={({ field }) => <TextField {...field} label="Nome fantasia" />} />
      <Stack direction="row" spacing={2}>
        <Controller name="state_registration" control={control} render={({ field, fieldState }) => <TextField {...field} label="Inscrição estadual" disabled={exempt} required={!exempt} error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />} />
        <Controller name="state_registration_exempt" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => { field.onChange(e.target.checked); if (e.target.checked) setValue('state_registration', '', { shouldDirty: true }); }} />} label="Isento" />} />
      </Stack>
      {customer.price_list ? <Typography>Condição comercial: <strong>{customer.price_list.name}</strong></Typography> : null}
      <Button type="submit" disabled={!formState.isDirty} loading={formState.isSubmitting} sx={{ alignSelf: 'flex-start' }}>Salvar alterações</Button>
    </Box>
  );
}

export default function ProfilePage() {
  const { customer } = useAuth();
  const settings = useSettings();
  if (!customer) return null;
  const email = settings.data?.store.email;
  return (
    <Box>
      <PageHeading>Dados cadastrais</PageHeading>
      <Stack spacing={4}>
        {customer.company ? <Card sx={{ p: 4 }}><CompanyForm customer={customer} /></Card> : null}
        <Card sx={{ p: 4 }}><ProfileForm customer={customer} /></Card>
        <Card sx={{ p: 4 }}>
          <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Privacidade</Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            {/* "Baixar meus dados" (GET /me/data-export) fica oculto: endpoint fora do MVP (ADR-026/ADR-034). */}
            {email ? <Button variant="text" color="error" href={`mailto:${email}?subject=${encodeURIComponent('Solicitação de exclusão de conta (LGPD)')}`}>Solicitar exclusão da conta</Button> : null}
          </Stack>
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 2 }}>
            Atendemos em até 15 dias. <Link href="/institucional/privacidade">Política de privacidade</Link>
          </Typography>
        </Card>
      </Stack>
    </Box>
  );
}
