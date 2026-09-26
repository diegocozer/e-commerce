import AddIcon from '@mui/icons-material/AddOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Autocomplete, Button, Chip, IconButton, Stack, TextField, Typography } from '@mui/material';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { IbgeCity, ShippingZone } from '@/shared/api/types';
import { formatCEP, maskCEP, onlyDigits } from '@/shared/formatters/document';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { ErrorState, LoadingBlock, notify } from '@/shared/ui';
import { applyServerErrors, FormErrorSummary, FormPage, FormSection, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { testZone, useCitySearch, useSaveZone, useZone } from '../api';
import { UFS } from '../schemas/ufs';
import { zoneSchema, type ZoneForm } from '../schemas/zone';

const toForm = (z: ShippingZone | null): ZoneForm => ({
  name: z?.name ?? '', description: z?.description ?? '', is_active: z?.is_active ?? true,
  postal_ranges: (z?.postal_ranges ?? []).map((r) => ({ start: formatCEP(r.start_postal_code), end: formatCEP(r.end_postal_code) })),
  cities: (z?.cities ?? []).map((c) => ({ city_ibge_code: c.city_ibge_code, city_name: c.city_name, state: c.state })),
  states: z?.states ?? [],
});

function CityPicker({ value, onChange }: { value: ZoneForm['cities']; onChange: (v: ZoneForm['cities']) => void }) {
  const [input, setInput] = useState('');
  const search = useDebouncedValue(input, 300);
  const { data = [], isFetching } = useCitySearch(search);
  const selected: IbgeCity[] = value.map((c) => ({ ibge_code: c.city_ibge_code, name: c.city_name, state: c.state as IbgeCity['state'] }));
  return (
    <Autocomplete
      multiple
      options={data}
      value={selected}
      loading={isFetching}
      filterOptions={(x) => x}
      inputValue={input}
      onInputChange={(_, v) => setInput(v)}
      isOptionEqualToValue={(a, b) => a.ibge_code === b.ibge_code}
      getOptionLabel={(c) => `${c.name}/${c.state}`}
      onChange={(_, v) => onChange(v.map((c) => ({ city_ibge_code: c.ibge_code, city_name: c.name, state: c.state })))}
      noOptionsText={input.length < 2 ? 'Digite ao menos 2 letras' : 'Nenhuma cidade'}
      renderInput={(p) => <TextField {...p} label="Cidades (IBGE)" placeholder="Buscar cidade…" />}
    />
  );
}

function ZoneTester({ zoneId }: { zoneId: number }) {
  const [cep, setCep] = useState('');
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
  const run = async () => {
    try {
      const r = await testZone(zoneId, onlyDigits(cep));
      setResult(r.matches ? { ok: true, text: `✓ Pertence a esta zona (${r.matched_by})` } : { ok: false, text: `✗ Não pertence a esta zona${r.destination.city ? ` (${r.destination.city}/${r.destination.state})` : ''}` });
    } catch (e) {
      setResult({ ok: false, text: errorMessage(e) });
    }
  };
  return (
    <FormSection id="testar" title="Testar CEP">
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
        <TextField label="CEP" value={cep} onChange={(e) => setCep(maskCEP(e.target.value))} sx={{ maxWidth: 180 }} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
        <Button onClick={() => void run()} disabled={onlyDigits(cep).length !== 8}>Testar</Button>
        {result && <Alert severity={result.ok ? 'success' : 'warning'} sx={{ flex: 1 }} role="status">{result.text}</Alert>}
      </Stack>
      <Typography variant="caption" color="text.secondary">Teste usa a zona salva (salve antes de testar alterações).</Typography>
    </FormSection>
  );
}

function ZoneEditor({ zone }: { zone: ShippingZone | null }) {
  const navigate = useNavigate();
  const save = useSaveZone(zone?.id ?? null);
  const defaults = useMemo(() => toForm(zone), [zone]);
  const { control, handleSubmit, reset, setError, formState } = useForm<ZoneForm>({ resolver: zodResolver(zoneSchema), defaultValues: defaults });
  const ranges = useFieldArray({ control, name: 'postal_ranges' });
  const [warnings, setWarnings] = useState<string[]>([]);
  const [extra, setExtra] = useState<string[]>([]);
  useBreadcrumb(zone ? zone.name : 'Nova zona');
  useEffect(() => reset(defaults), [defaults, reset]);

  const onSubmit = handleSubmit(async (v) => {
    setExtra([]);
    const body = {
      name: v.name, description: v.description || null, is_active: v.is_active, states: v.states,
      postal_ranges: v.postal_ranges.map((r) => ({ start_postal_code: onlyDigits(r.start), end_postal_code: onlyDigits(r.end) })),
      cities: v.cities.map((c) => ({ city_ibge_code: c.city_ibge_code })),
      ...(zone ? { expected_updated_at: zone.updated_at } : {}),
    };
    try {
      const res = await save.mutateAsync(body);
      setWarnings(res.warnings);
      notify.success(res.warnings.length ? 'Zona salva com avisos' : 'Zona salva');
      if (!zone) navigate(`/frete/zonas/${res.data.id}`, { replace: true });
      else reset(toForm(res.data));
    } catch (e) {
      const rest = applyServerErrors(e, setError, {
        fieldMap: Object.fromEntries(v.postal_ranges.flatMap((_, i) => [[`postal_ranges.${i}.start_postal_code`, `postal_ranges.${i}.start`], [`postal_ranges.${i}.end_postal_code`, `postal_ranges.${i}.end`]])),
      });
      if (!isApiError(e) || !e.fieldErrors) notify.error(errorMessage(e));
      setExtra(rest);
    }
  });

  return (
    <FormPage title={zone ? `Zona: ${zone.name}` : 'Nova zona'} back={{ to: '/frete/zonas', label: 'Zonas' }} isDirty={formState.isDirty} isSubmitting={formState.isSubmitting} onSubmit={(e) => void onSubmit(e)} onDiscard={() => reset(defaults)} saveLabel="Salvar zona">
      <FormErrorSummary errors={formState.errors} extra={extra} />
      {warnings.map((w) => <Alert key={w} severity="warning">{w}</Alert>)}
      <FormSection id="dados" title="Dados">
        <Stack spacing={3}>
          <RHFTextField control={control} name="name" label="Nome" required maxLength={120} />
          <RHFTextField control={control} name="description" label="Descrição" maxLength={255} />
          <RHFSwitch control={control} name="is_active" label="Ativa" />
        </Stack>
      </FormSection>
      <FormSection id="cobertura" title="Cobertura" description="Qualquer critério casa: faixa de CEP, cidade (IBGE) ou estado.">
        <Typography variant="h4" component="h3" sx={{ mb: 2 }}>Faixas de CEP</Typography>
        <Stack spacing={2}>
          {ranges.fields.map((f, i) => (
            <Stack key={f.id} direction="row" spacing={2} sx={{ alignItems: 'flex-start' }}>
              <Controller control={control} name={`postal_ranges.${i}.start`} render={({ field, fieldState }) => (
                <TextField {...field} label="CEP inicial" onChange={(e) => field.onChange(maskCEP(e.target.value))} error={!!fieldState.error} helperText={fieldState.error?.message} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
              )} />
              <Controller control={control} name={`postal_ranges.${i}.end`} render={({ field, fieldState }) => (
                <TextField {...field} label="CEP final" onChange={(e) => field.onChange(maskCEP(e.target.value))} error={!!fieldState.error} helperText={fieldState.error?.message} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
              )} />
              <IconButton aria-label={`Remover faixa ${i + 1}`} onClick={() => ranges.remove(i)}><DeleteIcon /></IconButton>
            </Stack>
          ))}
          <Button startIcon={<AddIcon />} onClick={() => ranges.append({ start: '', end: '' })} sx={{ alignSelf: 'flex-start' }}>Adicionar faixa</Button>
          {formState.errors.postal_ranges?.message && <Alert severity="error">{formState.errors.postal_ranges.message}</Alert>}
        </Stack>
        <Typography variant="h4" component="h3" sx={{ mt: 4, mb: 2 }}>Cidades</Typography>
        <Controller control={control} name="cities" render={({ field }) => <CityPicker value={field.value} onChange={field.onChange} />} />
        <Typography variant="h4" component="h3" sx={{ mt: 4, mb: 2 }}>Estados</Typography>
        <Controller control={control} name="states" render={({ field }) => (
          <Autocomplete multiple options={UFS as string[]} value={field.value} onChange={(_, v) => field.onChange(v)} renderValue={(vals, getItemProps) => vals.map((v, index) => <Chip {...getItemProps({ index })} key={v} label={v} size="small" />)} renderInput={(p) => <TextField {...p} label="UFs" />} />
        )} />
      </FormSection>
      {zone && <ZoneTester zoneId={zone.id} />}
    </FormPage>
  );
}

export default function ZoneFormPage() {
  const params = useParams();
  const id = params.id ? Number(params.id) : null;
  const q = useZone(id);
  if (id === null) return <ZoneEditor zone={null} />;
  if (q.isPending) return <LoadingBlock />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  return <ZoneEditor zone={q.data} />;
}
