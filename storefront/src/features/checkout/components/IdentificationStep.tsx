import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { updateProfile } from '@/features/account';
import { authKeys, useLogout } from '@/features/auth';
import { toApiError } from '@/shared/api/errors';
import type { Customer } from '@/shared/api/types';
import { formatCNPJ, formatCPF, isValidCPF } from '@/shared/formatters/document';
import { formatPhone, isValidPhone } from '@/shared/formatters/phone';
import { onlyDigits } from '@/shared/formatters/postalCode';
import { StepHeading } from './StepHeading';

export function IdentificationStep({ customer, onContinue }: { customer: Customer; onContinue: () => void }) {
  const logout = useLogout();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const needsPhone = customer.missing_fields.includes('phone');
  const needsCpf = customer.missing_fields.includes('cpf');
  const [phone, setPhone] = useState('');
  const [cpf, setCpf] = useState('');
  const [errors, setErrors] = useState<{ phone?: string; cpf?: string; general?: string }>({});
  const [saving, setSaving] = useState(false);

  const save = async () => {
    const e: typeof errors = {};
    if (needsPhone && !isValidPhone(phone)) e.phone = 'Informe um telefone com DDD';
    if (needsCpf && !isValidCPF(cpf)) e.cpf = 'CPF inválido';
    setErrors(e);
    if (Object.keys(e).length) return;
    setSaving(true);
    try {
      const updated = await updateProfile({ ...(needsPhone ? { phone: onlyDigits(phone) } : {}), ...(needsCpf ? { cpf: onlyDigits(cpf) } : {}) });
      qc.setQueryData(authKeys.me, updated);
      if (updated.profile_complete) onContinue();
    } catch (err) {
      const ae = toApiError(err);
      setErrors({ phone: ae.fieldMessage('phone') ?? undefined, cpf: ae.fieldMessage('cpf') ?? undefined, general: ae.isValidation ? undefined : ae.message });
    } finally {
      setSaving(false);
    }
  };

  const doc = customer.company ? `CNPJ ${formatCNPJ(customer.company.cnpj)} (${customer.company.legal_name})` : customer.cpf ? `CPF ${formatCPF(customer.cpf)}` : null;
  return (
    <Box>
      <StepHeading>Identificação</StepHeading>
      <Card sx={{ p: 4, mb: 4 }}>
        <Typography>
          Comprando como <strong>{customer.name}</strong> · {customer.email}
          {doc ? ` · ${doc}` : ''}
        </Typography>
        <Link component="button" type="button" onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/entrar?redirect=%2Fcheckout') })} sx={{ mt: 2 }}>
          Não é você? Sair
        </Link>
      </Card>
      {!customer.profile_complete ? (
        <Stack spacing={3} sx={{ mb: 4 }}>
          <Alert severity="info">Complete seus dados de faturamento para continuar.</Alert>
          {errors.general ? <Alert severity="error">{errors.general}</Alert> : null}
          {needsPhone ? (
            <TextField label="Telefone" required value={phone} onChange={(e) => setPhone(formatPhone(e.target.value))} error={Boolean(errors.phone)} helperText={errors.phone} slotProps={{ htmlInput: { inputMode: 'tel', autoComplete: 'tel' } }} />
          ) : null}
          {needsCpf ? (
            <TextField label="CPF" required value={cpf} onChange={(e) => setCpf(formatCPF(e.target.value))} error={Boolean(errors.cpf)} helperText={errors.cpf} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
          ) : null}
          {!needsPhone && !needsCpf ? (
            <Alert severity="warning">
              Faltam dados: {customer.missing_fields.join(', ')}. <Link href="/conta/dados">Completar cadastro</Link>
            </Alert>
          ) : null}
        </Stack>
      ) : null}
      <Box sx={{ display: 'flex', justifyContent: 'flex-end' }}>
        {customer.profile_complete ? (
          <Button onClick={onContinue}>Continuar</Button>
        ) : needsPhone || needsCpf ? (
          <Button onClick={save} loading={saving}>
            Salvar e continuar
          </Button>
        ) : null}
      </Box>
    </Box>
  );
}
