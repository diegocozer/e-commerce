import AddIcon from '@mui/icons-material/AddOutlined';
import ArrowDownIcon from '@mui/icons-material/ArrowDownwardOutlined';
import ArrowUpIcon from '@mui/icons-material/ArrowUpwardOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Button, Grid, IconButton, Typography } from '@mui/material';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import { errorMessage } from '@/shared/api/errors';
import type { ShippingMethod, UF } from '@/shared/api/types';
import { formatCEP, onlyDigits } from '@/shared/formatters/document';
import { SHIPPING_METHOD_TYPE, WEIGHT_BASIS } from '@/shared/formatters/labels';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog, RHFIntField, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { deleteMethod, useCarriers, useReorderMethods, useSaveMethod, useShippingMethods } from '../api';
import { UFS } from '../schemas/ufs';

const methodSchema = z
  .object({
    name: z.string().trim().min(1, 'Informe o nome.').max(120),
    code: z.string().trim().regex(/^[a-z0-9-]{1,60}$/, 'Use letras minúsculas, números e hífen.'),
    type: z.enum(['pickup', 'own_delivery', 'table_rate', 'carrier']),
    carrier_id: z.number().nullable(),
    carrier_service_code: z.string(),
    description: z.string().max(500),
    delivery_days_min: z.number().int().nullable(),
    delivery_days_max: z.number().int().nullable(),
    handling_days: z.number().int().nullable(),
    weight_basis: z.enum(['real', 'chargeable']),
    cubic_divisor: z.number().int().nullable(),
    accepts_free_shipping_coupon: z.boolean(),
    is_active: z.boolean(),
    pickup: z.object({ street: z.string(), number: z.string(), complement: z.string(), district: z.string(), city: z.string(), state: z.string(), postal_code: z.string(), instructions: z.string().max(500), opening_hours: z.string().max(200) }),
  })
  .superRefine((v, ctx) => {
    const add = (path: (string | number)[], message: string) => ctx.addIssue({ code: 'custom', path, message });
    if (v.delivery_days_min === null || v.delivery_days_min < 0) add(['delivery_days_min'], 'Informe o prazo mínimo (≥ 0).');
    if (v.delivery_days_max === null || (v.delivery_days_min !== null && v.delivery_days_max < v.delivery_days_min)) add(['delivery_days_max'], 'O prazo máximo deve ser ≥ mínimo.');
    if (v.handling_days !== null && v.handling_days < 0) add(['handling_days'], 'Não pode ser negativo.');
    if (v.cubic_divisor !== null && v.cubic_divisor <= 0) add(['cubic_divisor'], 'Deve ser maior que zero.');
    if (v.type === 'carrier') {
      if (v.carrier_id === null) add(['carrier_id'], 'Selecione a transportadora.');
      if (!v.carrier_service_code.trim()) add(['carrier_service_code'], 'Informe o código do serviço.');
    }
    if (v.type === 'pickup') {
      if (!v.pickup.street.trim()) add(['pickup', 'street'], 'Informe a rua.');
      if (!v.pickup.city.trim()) add(['pickup', 'city'], 'Informe a cidade.');
      if (!v.pickup.state) add(['pickup', 'state'], 'Informe a UF.');
      if (onlyDigits(v.pickup.postal_code).length !== 8) add(['pickup', 'postal_code'], 'CEP inválido.');
    }
  });
type MethodForm = z.infer<typeof methodSchema>;

function MethodDialog({ method, onClose }: { method: ShippingMethod | null; onClose: () => void }) {
  const carriers = useCarriers().data ?? [];
  const save = useSaveMethod(method?.id ?? null);
  const p = method?.pickup;
  const { control, handleSubmit, setError } = useForm<MethodForm>({
    resolver: zodResolver(methodSchema),
    defaultValues: {
      name: method?.name ?? '', code: method?.code ?? '', type: method?.type ?? 'own_delivery', carrier_id: method?.carrier_id ?? null, carrier_service_code: method?.carrier_service_code ?? '',
      description: method?.description ?? '', delivery_days_min: method?.delivery_days_min ?? 1, delivery_days_max: method?.delivery_days_max ?? 1, handling_days: method?.handling_days ?? 0,
      weight_basis: method?.weight_basis ?? 'real', cubic_divisor: method?.cubic_divisor ?? null, accepts_free_shipping_coupon: method?.accepts_free_shipping_coupon ?? true, is_active: method?.is_active ?? true,
      pickup: { street: p?.street ?? '', number: p?.number ?? '', complement: p?.complement ?? '', district: p?.district ?? '', city: p?.city ?? '', state: p?.state ?? 'SC', postal_code: p ? formatCEP(p.postal_code) : '', instructions: p?.instructions ?? '', opening_hours: p?.opening_hours ?? '' },
    },
  });
  const type = useWatch({ control, name: 'type' });
  const { error, run } = useFormSubmit(setError);
  const onSubmit = handleSubmit(async (v) => {
    const { pickup, ...rest } = v;
    const body: Record<string, unknown> = {
      ...rest, description: v.description || null, carrier_id: v.type === 'carrier' ? v.carrier_id : null, carrier_service_code: v.type === 'carrier' ? v.carrier_service_code : null,
      pickup: v.type === 'pickup' ? { ...pickup, complement: pickup.complement || null, postal_code: onlyDigits(pickup.postal_code), state: pickup.state as UF, instructions: pickup.instructions || null, opening_hours: pickup.opening_hours || null } : null,
    };
    if (method) delete body.type; // imutável
    if (await run(() => save.mutateAsync(body))) {
      notify.success('Método salvo');
      onClose();
    }
  });
  return (
    <FormDialog open title={method ? `Método: ${method.name}` : 'Novo método de entrega'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} maxWidth="md">
      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 6 }}><RHFTextField control={control} name="name" label="Nome exibido ao cliente" required /></Grid>
        <Grid size={{ xs: 12, md: 3 }}><RHFTextField control={control} name="code" label="Código" required /></Grid>
        <Grid size={{ xs: 12, md: 3 }}><RHFSelect control={control} name="type" label="Tipo" required disabled={!!method} options={Object.entries(SHIPPING_METHOD_TYPE).map(([value, label]) => ({ value, label }))} /></Grid>
        {type === 'carrier' && (
          <>
            <Grid size={{ xs: 12, md: 6 }}><RHFSelect control={control} name="carrier_id" label="Transportadora" required options={carriers.map((c) => ({ value: c.id, label: c.name }))} /></Grid>
            <Grid size={{ xs: 12, md: 6 }}><RHFTextField control={control} name="carrier_service_code" label="Código do serviço" required placeholder="Ex.: SEDEX" /></Grid>
          </>
        )}
        <Grid size={12}><RHFTextField control={control} name="description" label="Descrição" maxLength={500} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="delivery_days_min" label="Prazo mín." unit="dias úteis" required /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="delivery_days_max" label="Prazo máx." unit="dias úteis" required /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="handling_days" label="Manuseio" unit="dias" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFSelect control={control} name="weight_basis" label="Base de peso" options={Object.entries(WEIGHT_BASIS).map(([value, label]) => ({ value, label }))} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="cubic_divisor" label="Divisor cúbico" helperText="Ex.: 6000" /></Grid>
        <Grid size={{ xs: 12, md: 9 }}><RHFSwitch control={control} name="accepts_free_shipping_coupon" label="Aceita cupom de frete grátis" /></Grid>
        {type === 'pickup' && (
          <>
            <Grid size={12}><Typography variant="overline">Endereço de retirada</Typography></Grid>
            <Grid size={{ xs: 12, md: 3 }}><RHFTextField control={control} name="pickup.postal_code" label="CEP" required /></Grid>
            <Grid size={{ xs: 12, md: 6 }}><RHFTextField control={control} name="pickup.street" label="Rua" required /></Grid>
            <Grid size={{ xs: 12, md: 3 }}><RHFTextField control={control} name="pickup.number" label="Número" /></Grid>
            <Grid size={{ xs: 12, md: 4 }}><RHFTextField control={control} name="pickup.complement" label="Complemento" /></Grid>
            <Grid size={{ xs: 12, md: 4 }}><RHFTextField control={control} name="pickup.district" label="Bairro" /></Grid>
            <Grid size={{ xs: 8, md: 3 }}><RHFTextField control={control} name="pickup.city" label="Cidade" required /></Grid>
            <Grid size={{ xs: 4, md: 1 }}><RHFSelect control={control} name="pickup.state" label="UF" options={UFS.map((u) => ({ value: u, label: u }))} /></Grid>
            <Grid size={{ xs: 12, md: 6 }}><RHFTextField control={control} name="pickup.opening_hours" label="Horário de retirada" maxLength={200} /></Grid>
            <Grid size={{ xs: 12, md: 6 }}><RHFTextField control={control} name="pickup.instructions" label="Instruções" placeholder="Leve documento com foto" maxLength={500} /></Grid>
          </>
        )}
        <Grid size={12}><RHFSwitch control={control} name="is_active" label="Ativo" /></Grid>
      </Grid>
    </FormDialog>
  );
}

export default function MethodsPage() {
  const q = useShippingMethods();
  const reorder = useReorderMethods();
  const [editing, setEditing] = useState<ShippingMethod | 'new' | null>(null);
  const remove = useRemoveWithConfirm<ShippingMethod>({ remove: (m) => deleteMethod(m.id), invalidate: ['admin', 'shipping'], successMessage: 'Método excluído' });
  const rows = q.data ?? [];
  const move = (i: number, dir: -1 | 1) => {
    const ids = rows.map((m) => m.id);
    [ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
    reorder.mutate(ids, { onError: (e) => notify.error(errorMessage(e)) });
  };
  const columns: Column<ShippingMethod>[] = [
    {
      key: 'order',
      header: 'Ordem',
      render: (m) => {
        const i = rows.indexOf(m);
        return (
          <span onClick={(e) => e.stopPropagation()}>
            <IconButton aria-label={`Subir ${m.name}`} disabled={i === 0} onClick={() => move(i, -1)}><ArrowUpIcon fontSize="small" /></IconButton>
            <IconButton aria-label={`Descer ${m.name}`} disabled={i === rows.length - 1} onClick={() => move(i, 1)}><ArrowDownIcon fontSize="small" /></IconButton>
          </span>
        );
      },
    },
    { key: 'name', header: 'Nome', render: (m) => m.name },
    { key: 'type', header: 'Tipo', render: (m) => SHIPPING_METHOD_TYPE[m.type] },
    { key: 'days', header: 'Prazo padrão', render: (m) => (m.delivery_days_min === m.delivery_days_max ? `${m.delivery_days_max} dia(s) útil(eis)` : `${m.delivery_days_min} a ${m.delivery_days_max} dias úteis`) },
    { key: 'rules', header: 'Regras', align: 'right', render: (m) => m.rules_count },
    { key: 'status', header: 'Status', render: (m) => <ActiveChip active={m.is_active} /> },
    { key: 'act', header: '', align: 'right', render: (m) => <Button size="small" color="error" onClick={(e) => { e.stopPropagation(); remove.ask(m); }}>Excluir</Button> },
  ];
  return (
    <>
      <PageHeader title="Métodos de entrega" subtitle="A ordem define o desempate na exibição das opções ao cliente." actions={<Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Novo método</Button>} />
      <DataTable caption="Métodos de entrega" columns={columns} rows={q.data} rowKey={(m) => m.id} loading={q.isPending} fetching={reorder.isPending} error={q.error} onRetry={() => void q.refetch()} onRowClick={(m) => setEditing(m)} emptyTitle="Nenhum método cadastrado" />
      {editing && <MethodDialog method={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir o método "${remove.target?.name ?? ''}"?`} confirmLabel="Excluir método" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
