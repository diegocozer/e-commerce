import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import CircularProgress from '@mui/material/CircularProgress';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { catalogKeys, fetchPostalCode } from '@/features/catalog/api';
import { toApiError } from '@/shared/api/errors';
import type { Address, AddressInput } from '@/shared/api/types';
import { formatCEP, onlyDigits } from '@/shared/formatters/postalCode';
import { formatPhone } from '@/shared/formatters/phone';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { addressSchema, type AddressFormValues } from '../schemas/address';

const FIELDS = ['postal_code', 'street', 'number', 'complement', 'district', 'reference', 'recipient_name', 'phone', 'label', 'is_default'] as const;

function toValues(a?: Address | null, defaults?: { recipient_name?: string; phone?: string | null }): AddressFormValues {
  return {
    postal_code: formatCEP(a?.postal_code ?? ''),
    street: a?.street ?? '',
    number: a?.number === 'S/N' ? '' : (a?.number ?? ''),
    no_number: a?.number === 'S/N',
    complement: a?.complement ?? '',
    district: a?.district ?? '',
    city: a?.city ?? '',
    state: a?.state ?? '',
    reference: a?.reference ?? '',
    recipient_name: a?.recipient_name ?? defaults?.recipient_name ?? '',
    phone: formatPhone(a?.phone ?? defaults?.phone ?? ''),
    label: a?.label ?? '',
    is_default: a?.is_default ?? false,
  };
}

/** Formulário de endereço com autofill de CEP (UX §6.11) — cidade/UF derivadas do CEP no backend. */
export function AddressForm({
  initial,
  defaults,
  submitLabel = 'Salvar endereço',
  onSubmit,
  onCancel,
}: {
  initial?: Address | null;
  defaults?: { recipient_name?: string; phone?: string | null };
  submitLabel?: string;
  onSubmit: (input: AddressInput) => Promise<unknown>;
  onCancel?: () => void;
}) {
  const qc = useQueryClient();
  const form = useForm<AddressFormValues>({ resolver: zodResolver(addressSchema), defaultValues: toValues(initial, defaults), mode: 'onBlur', reValidateMode: 'onChange' });
  const { control, handleSubmit, setValue, setError, formState, watch } = form;
  const [lookup, setLookup] = useState<'idle' | 'loading' | 'found' | 'generic' | 'failed'>(initial ? 'found' : 'idle');
  const [lookupMsg, setLookupMsg] = useState<string | null>(null);
  const [topErrors, setTopErrors] = useState<string[]>([]);
  const numberRef = useRef<HTMLInputElement>(null);
  const noNumber = watch('no_number');
  const city = watch('city');
  const state = watch('state');

  async function lookupCep(cep: string) {
    setLookup('loading');
    setLookupMsg(null);
    try {
      const info = await qc.fetchQuery({ queryKey: catalogKeys.postalCode(cep), queryFn: () => fetchPostalCode(cep), staleTime: 86_400_000 });
      setValue('city', info.city);
      setValue('state', info.state);
      if (info.street) setValue('street', info.street, { shouldValidate: true });
      if (info.district) setValue('district', info.district, { shouldValidate: true });
      setLookup(info.street ? 'found' : 'generic');
      setLookupMsg(`Endereço encontrado: ${[info.street, info.district, `${info.city}/${info.state}`].filter(Boolean).join(', ')}`);
      setTimeout(() => numberRef.current?.focus(), 0);
    } catch (err) {
      const e = toApiError(err);
      setValue('city', '');
      setValue('state', '');
      setLookup('failed');
      if (e.status === 404 || e.isValidation) setError('postal_code', { type: 'server', message: 'CEP não encontrado. Confira ou preencha o endereço manualmente.' });
      else setLookupMsg('Não conseguimos buscar o CEP. Preencha o endereço.');
    }
  }

  const submit = handleSubmit(async (v) => {
    setTopErrors([]);
    const input: AddressInput = {
      postal_code: onlyDigits(v.postal_code),
      street: v.street.trim(),
      number: v.no_number ? 'S/N' : v.number.trim(),
      complement: v.complement.trim() || null,
      district: v.district.trim(),
      reference: v.reference.trim() || null,
      recipient_name: v.recipient_name.trim(),
      phone: onlyDigits(v.phone),
      label: v.label.trim() || null,
      is_default: v.is_default,
    };
    try {
      await onSubmit(input);
    } catch (err) {
      const e = toApiError(err);
      if (e.code === 'postal_code_lookup_unavailable') setTopErrors(['Não conseguimos validar o CEP agora. Tente novamente em instantes.']);
      else setTopErrors(applyServerErrors(err, setError, FIELDS, { address: 'postal_code' }));
    }
  });

  const text = (name: keyof AddressFormValues, label: string, extra: Partial<React.ComponentProps<typeof TextField>> = {}) => (
    <Controller
      name={name}
      control={control}
      render={({ field, fieldState }) => (
        <TextField
          {...field}
          value={field.value as string}
          label={label}
          error={Boolean(fieldState.error)}
          helperText={fieldState.error?.message}
          {...extra}
        />
      )}
    />
  );

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <Typography variant="caption" color="text.secondary">* obrigatório</Typography>
      {topErrors.length ? (
        <Alert severity="error" role="alert">
          {topErrors.map((m) => <div key={m}>{m}</div>)}
        </Alert>
      ) : null}
      <Grid container spacing={3}>
        <Grid size={{ xs: 12, sm: 5 }}>
          <Controller
            name="postal_code"
            control={control}
            render={({ field, fieldState }) => (
              <TextField
                {...field}
                label="CEP"
                required
                onChange={(e) => {
                  const masked = formatCEP(e.target.value);
                  field.onChange(masked);
                  const d = onlyDigits(masked);
                  if (d.length === 8) void lookupCep(d);
                }}
                error={Boolean(fieldState.error)}
                helperText={fieldState.error?.message}
                slotProps={{
                  htmlInput: { inputMode: 'numeric', autoComplete: 'postal-code', maxLength: 9 },
                  input: { endAdornment: lookup === 'loading' ? <InputAdornment position="end"><CircularProgress size={18} aria-label="Buscando CEP" /></InputAdornment> : undefined },
                }}
              />
            )}
          />
        </Grid>
        <Grid size={{ xs: 12, sm: 7 }}>
          <TextField label="Cidade/UF" value={city ? `${city}/${state}` : ''} disabled slotProps={{ htmlInput: { readOnly: true, autoComplete: 'address-level2' } }} helperText="Preenchido pelo CEP" />
        </Grid>
        <Grid size={12}>
          <Typography aria-live="polite" variant="caption" color={lookup === 'failed' ? 'warning.main' : 'text.secondary'}>
            {lookupMsg}
          </Typography>
        </Grid>
        <Grid size={{ xs: 12, sm: 8 }}>{text('street', 'Rua', { required: true, slotProps: { htmlInput: { autoComplete: 'address-line1' } } })}</Grid>
        <Grid size={{ xs: 8, sm: 4 }}>
          <Controller
            name="number"
            control={control}
            render={({ field, fieldState }) => (
              <TextField {...field} inputRef={(el: HTMLInputElement | null) => { field.ref(el); numberRef.current = el; }} label="Número" required={!noNumber} disabled={noNumber} error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />
            )}
          />
        </Grid>
        <Grid size={{ xs: 4, sm: 12 }}>
          <Controller name="no_number" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="Sem número" />} />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>{text('complement', 'Complemento', { slotProps: { htmlInput: { autoComplete: 'address-line2' } } })}</Grid>
        <Grid size={{ xs: 12, sm: 6 }}>{text('district', 'Bairro', { required: true })}</Grid>
        <Grid size={12}>{text('reference', 'Referência')}</Grid>
        <Grid size={{ xs: 12, sm: 7 }}>{text('recipient_name', 'Nome do destinatário', { required: true, slotProps: { htmlInput: { autoComplete: 'name' } } })}</Grid>
        <Grid size={{ xs: 12, sm: 5 }}>
          <Controller
            name="phone"
            control={control}
            render={({ field, fieldState }) => (
              <TextField {...field} label="Telefone" required onChange={(e) => field.onChange(formatPhone(e.target.value))} error={Boolean(fieldState.error)} helperText={fieldState.error?.message} slotProps={{ htmlInput: { inputMode: 'tel', autoComplete: 'tel' } }} />
            )}
          />
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>{text('label', 'Apelido (ex.: Casa, Oficina)')}</Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <Controller name="is_default" control={control} render={({ field }) => <FormControlLabel control={<Checkbox checked={field.value} onChange={(e) => field.onChange(e.target.checked)} />} label="Salvar como endereço padrão" />} />
        </Grid>
      </Grid>
      <Stack direction="row" spacing={2} sx={{ justifyContent: "flex-end" }}>
        {onCancel ? <Button variant="outlined" onClick={onCancel}>Cancelar</Button> : null}
        <Button type="submit" loading={formState.isSubmitting}>{submitLabel}</Button>
      </Stack>
    </Box>
  );
}
