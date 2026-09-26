import AddIcon from '@mui/icons-material/AddOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Button, Grid, List, ListItem, ListItemText, Stack, Typography } from '@mui/material';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { errorMessage } from '@/shared/api/errors';
import type { Coupon } from '@/shared/api/types';
import { formatDateTime, isoToLocalInput, localInputToIso } from '@/shared/formatters/date';
import { COUPON_STATUS, COUPON_TYPE } from '@/shared/formatters/labels';
import { formatBp, formatBRL } from '@/shared/formatters/money';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ConfirmDialog, DataTable, FilterSelect, LabelChip, ListToolbar, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog, RHFDateTimeField, RHFIntField, RHFMoneyField, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { RHFPercentField } from '@/shared/ui/form/PercentField';
import { deleteCoupon, generateCouponCode, useCouponRedemptions, useCoupons, useSaveCoupon } from '../api';
import { couponSchema, type CouponForm } from '../schemas';

function Redemptions({ id }: { id: number }) {
  const q = useCouponRedemptions(id);
  if (!q.data) return null;
  return (
    <>
      <Typography variant="h4" component="h3">Usos ({q.data.meta.total})</Typography>
      <List dense>
        {q.data.data.map((r) => (
          <ListItem key={r.id} disableGutters>
            <ListItemText primary={`${r.order.number} · ${r.customer.name} · -${formatBRL(r.discount_cents)}`} secondary={`${formatDateTime(r.created_at)}${r.cancelled_at ? ' · pedido cancelado' : ''}`} />
          </ListItem>
        ))}
      </List>
    </>
  );
}

function CouponDialog({ coupon, onClose }: { coupon: Coupon | null; onClose: () => void }) {
  const save = useSaveCoupon(coupon?.id ?? null);
  const { control, handleSubmit, setError, setValue } = useForm<CouponForm>({
    resolver: zodResolver(couponSchema),
    defaultValues: {
      code: coupon?.code ?? '', description: coupon?.description ?? '', type: coupon?.type ?? 'percent',
      value_bp: coupon?.type === 'percent' ? coupon.value : null, value_cents: coupon?.type === 'fixed' ? coupon.value : null,
      min_order_cents: coupon?.min_order_cents ?? 0, max_discount_cents: coupon?.max_discount_cents ?? null, starts_at: isoToLocalInput(coupon?.starts_at), ends_at: isoToLocalInput(coupon?.ends_at),
      usage_limit: coupon?.usage_limit ?? null, usage_limit_per_customer: coupon?.usage_limit_per_customer ?? null, is_active: coupon?.is_active ?? true,
    },
  });
  const type = useWatch({ control, name: 'type' });
  const { error, run } = useFormSubmit(setError, { value: type === 'percent' ? 'value_bp' : 'value_cents' });
  const onSubmit = handleSubmit(async (v) => {
    const body = {
      code: v.code.toUpperCase(), description: v.description || null, type: v.type, value: v.type === 'percent' ? v.value_bp : v.type === 'fixed' ? v.value_cents : 0,
      min_order_cents: v.min_order_cents ?? 0, max_discount_cents: v.type === 'percent' ? v.max_discount_cents : null, starts_at: localInputToIso(v.starts_at), ends_at: localInputToIso(v.ends_at),
      usage_limit: v.usage_limit, usage_limit_per_customer: v.usage_limit_per_customer, is_active: v.is_active,
    };
    if (await run(() => save.mutateAsync(body))) {
      notify.success('Cupom salvo');
      onClose();
    }
  });
  return (
    <FormDialog open title={coupon ? `Cupom ${coupon.code}` : 'Novo cupom'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} maxWidth="md">
      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 6 }}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'flex-start' }}>
            <RHFTextField control={control} name="code" label="Código" required />
            <Button onClick={() => generateCouponCode().then((r) => setValue('code', r.code, { shouldDirty: true, shouldValidate: true })).catch((e) => notify.error(errorMessage(e)))} sx={{ whiteSpace: 'nowrap', mt: 0.5 }}>
              Gerar
            </Button>
          </Stack>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFSelect control={control} name="type" label="Tipo" options={Object.entries(COUPON_TYPE).map(([value, label]) => ({ value, label }))} /></Grid>
        <Grid size={12}><RHFTextField control={control} name="description" label="Descrição" maxLength={255} /></Grid>
        {type === 'percent' && <Grid size={{ xs: 12, md: 4 }}><RHFPercentField control={control} name="value_bp" label="Percentual" /></Grid>}
        {type === 'fixed' && <Grid size={{ xs: 12, md: 4 }}><RHFMoneyField control={control} name="value_cents" label="Valor do desconto" required /></Grid>}
        <Grid size={{ xs: 12, md: 4 }}><RHFMoneyField control={control} name="min_order_cents" label="Pedido mínimo" /></Grid>
        {type === 'percent' && <Grid size={{ xs: 12, md: 4 }}><RHFMoneyField control={control} name="max_discount_cents" label="Desconto máximo" /></Grid>}
        <Grid size={{ xs: 12, md: 6 }}><RHFDateTimeField control={control} name="starts_at" label="Início (opcional)" /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFDateTimeField control={control} name="ends_at" label="Fim (opcional)" /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFIntField control={control} name="usage_limit" label="Limite de usos total" helperText="Vazio = ilimitado" /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFIntField control={control} name="usage_limit_per_customer" label="Limite por cliente" helperText="Vazio = ilimitado" /></Grid>
        <Grid size={12}><RHFSwitch control={control} name="is_active" label="Ativo" /></Grid>
        {coupon && <Grid size={12}><Redemptions id={coupon.id} /></Grid>}
      </Grid>
    </FormDialog>
  );
}

export default function CouponsPage() {
  const { params, apiParams, update, clear, activeFilterCount } = useListParams();
  const q = useCoupons(apiParams);
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
  const remove = useRemoveWithConfirm<Coupon>({ remove: (c) => deleteCoupon(c.id), invalidate: ['admin', 'coupons'], successMessage: 'Cupom excluído' });
  const columns: Column<Coupon>[] = [
    { key: 'code', header: 'Código', render: (c) => <strong>{c.code}</strong> },
    { key: 'type', header: 'Tipo', render: (c) => COUPON_TYPE[c.type] },
    { key: 'value', header: 'Valor', align: 'right', render: (c) => (c.type === 'percent' ? formatBp(c.value) : c.type === 'fixed' ? formatBRL(c.value) : '—') },
    { key: 'min', header: 'Pedido mínimo', align: 'right', hideBelow: 'md', render: (c) => formatBRL(c.min_order_cents) },
    { key: 'uses', header: 'Usos', align: 'right', render: (c) => `${c.times_used}${c.usage_limit ? ` / ${c.usage_limit}` : ''}` },
    { key: 'period', header: 'Vigência', hideBelow: 'lg', render: (c) => (c.starts_at || c.ends_at ? `${c.starts_at ? formatDateTime(c.starts_at) : '—'} → ${c.ends_at ? formatDateTime(c.ends_at) : 'sem fim'}` : 'Sempre') },
    { key: 'status', header: 'Status', render: (c) => <LabelChip label={COUPON_STATUS[c.status].label} color={COUPON_STATUS[c.status].color} /> },
    { key: 'act', header: '', align: 'right', render: (c) => <Button size="small" color="error" onClick={(e) => { e.stopPropagation(); remove.ask(c); }}>Excluir</Button> },
  ];
  return (
    <>
      <PageHeader title="Cupons" count={q.data?.meta.total} actions={<Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Novo cupom</Button>} />
      <ListToolbar q={params.q} onSearch={(v) => update({ q: v })} placeholder="Buscar código…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Status" value={params.filters.status ?? ''} onChange={(v) => update({ status: v })} options={Object.entries(COUPON_STATUS).map(([value, s]) => ({ value, label: s.label }))} width={150} />
        <FilterSelect label="Tipo" value={params.filters.type ?? ''} onChange={(v) => update({ type: v })} options={Object.entries(COUPON_TYPE).map(([value, label]) => ({ value, label }))} width={150} />
      </ListToolbar>
      <DataTable caption="Cupons" columns={columns} rows={q.data?.data} rowKey={(c) => c.id} meta={q.data?.meta} loading={q.isPending} fetching={q.isFetching} error={q.error} onRetry={() => void q.refetch()} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} onRowClick={(c) => setEditing(c)} emptyTitle="Nenhum cupom cadastrado" filtered={activeFilterCount > 0} onClearFilters={clear} />
      {editing && <CouponDialog coupon={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir o cupom ${remove.target?.code ?? ''}?`} confirmLabel="Excluir cupom" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
