import AddIcon from '@mui/icons-material/AddOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Autocomplete, Button, Grid, Stack, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { errorMessage } from '@/shared/api/errors';
import { flattenCategories, useBrandOptions, useCategoryTree } from '@/shared/api/lookups';
import type { AdminVariantPickerItem, Promotion } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { formatDateTime, isoToLocalInput, localInputToIso } from '@/shared/formatters/date';
import { PROMOTION_STATUS, PROMOTION_TYPE } from '@/shared/formatters/labels';
import { formatBp, formatBRL } from '@/shared/formatters/money';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ConfirmDialog, DataTable, FilterSelect, LabelChip, ListToolbar, notify, PageHeader, VariantPicker, type Column } from '@/shared/ui';
import { FormDialog, RHFDateTimeField, RHFIntField, RHFMoneyField, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { RHFPercentField } from '@/shared/ui/form/PercentField';
import { deletePromotion, previewPromotion, usePromotions, useSavePromotion } from '../api';
import { promotionSchema, type PromotionForm } from '../schemas';

function PromoPreview({ promo }: { promo: Promotion }) {
  const [variant, setVariant] = useState<AdminVariantPickerItem | null>(null);
  const [result, setResult] = useState<string | null>(null);
  return (
    <Stack spacing={2}>
      <Typography variant="h4" component="h3">Prévia</Typography>
      <VariantPicker
        value={variant}
        onChange={(v) => {
          setVariant(v);
          setResult(null);
          if (v)
            previewPromotion(promo.id, [v.id])
              .then((rows) => rows[0] && setResult(`${v.product.name}: ${formatBRL(rows[0].base_price_cents)} → ${formatBRL(rows[0].promo_price_cents)}`))
              .catch((e) => notify.error(errorMessage(e)));
        }}
      />
      {result && <Alert severity="success" icon={false} className="num">{result}</Alert>}
    </Stack>
  );
}

function PromotionDialog({ promo, onClose }: { promo: Promotion | null; onClose: () => void }) {
  const save = useSavePromotion(promo?.id ?? null);
  const canManage = useCan('promotions.manage');
  const cats = flattenCategories(useCategoryTree().data);
  const brands = useBrandOptions().data ?? [];
  const { control, handleSubmit, setError, formState } = useForm<PromotionForm>({
    resolver: zodResolver(promotionSchema),
    defaultValues: {
      name: promo?.name ?? '', description: promo?.description ?? '', discount_type: promo?.discount_type ?? 'percent',
      value_bp: promo?.discount_type === 'percent' ? promo.value : null, value_cents: promo?.discount_type === 'fixed' ? promo.value : null,
      scope: promo?.scope ?? 'targeted', starts_at: isoToLocalInput(promo?.starts_at), ends_at: isoToLocalInput(promo?.ends_at), is_active: promo?.is_active ?? true,
      priority: promo?.priority ?? 0, product_ids: promo?.product_ids ?? [], category_ids: promo?.category_ids ?? [], brand_ids: promo?.brand_ids ?? [],
    },
  });
  const [type, scope] = useWatch({ control, name: ['discount_type', 'scope'] });
  const { error, run } = useFormSubmit(setError, { value: type === 'percent' ? 'value_bp' : 'value_cents' });
  const onSubmit = handleSubmit(async (v) => {
    const body = {
      name: v.name, description: v.description || null, discount_type: v.discount_type, value: v.discount_type === 'percent' ? v.value_bp : v.value_cents,
      scope: v.scope, starts_at: localInputToIso(v.starts_at), ends_at: localInputToIso(v.ends_at), is_active: v.is_active, priority: v.priority ?? 0,
      product_ids: v.scope === 'targeted' ? v.product_ids : [], category_ids: v.scope === 'targeted' ? v.category_ids : [], brand_ids: v.scope === 'targeted' ? v.brand_ids : [],
    };
    if (await run(() => save.mutateAsync(body))) {
      notify.success('Promoção salva');
      onClose();
    }
  });
  return (
    <FormDialog open title={promo ? `Promoção: ${promo.name}` : 'Nova promoção'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} maxWidth="md" readOnly={!canManage}>
      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 8 }}><RHFTextField control={control} name="name" label="Nome" required /></Grid>
        <Grid size={{ xs: 12, md: 4 }}><RHFIntField control={control} name="priority" label="Prioridade" helperText="Maior prevalece em empate" /></Grid>
        <Grid size={12}><RHFTextField control={control} name="description" label="Descrição" multiline minRows={2} maxLength={500} /></Grid>
        <Grid size={{ xs: 12, md: 4 }}><RHFSelect control={control} name="discount_type" label="Tipo de desconto" options={Object.entries(PROMOTION_TYPE).map(([value, label]) => ({ value, label }))} /></Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          {type === 'percent' ? <RHFPercentField control={control} name="value_bp" label="Percentual" /> : <RHFMoneyField control={control} name="value_cents" label="Desconto por unidade de venda" required />}
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}><RHFSelect control={control} name="scope" label="Aplicar a" options={[{ value: 'all', label: 'Todo o catálogo' }, { value: 'targeted', label: 'Produtos/categorias/marcas' }]} /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFDateTimeField control={control} name="starts_at" label="Início" required /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFDateTimeField control={control} name="ends_at" label="Fim (opcional)" /></Grid>
        {scope === 'targeted' && (
          <>
            <Grid size={{ xs: 12, md: 6 }}>
              <Controller control={control} name="category_ids" render={({ field }) => (
                <Autocomplete multiple options={cats.map((c) => c.id)} value={field.value} onChange={(_, v) => field.onChange(v)} getOptionLabel={(id) => cats.find((c) => c.id === id)?.label ?? String(id)} renderInput={(p) => <TextField {...p} label="Categorias" error={!!formState.errors.scope} helperText={formState.errors.scope?.message} />} />
              )} />
            </Grid>
            <Grid size={{ xs: 12, md: 6 }}>
              <Controller control={control} name="brand_ids" render={({ field }) => (
                <Autocomplete multiple options={brands.map((b) => b.id)} value={field.value} onChange={(_, v) => field.onChange(v)} getOptionLabel={(id) => brands.find((b) => b.id === id)?.name ?? String(id)} renderInput={(p) => <TextField {...p} label="Marcas" />} />
              )} />
            </Grid>
            {promo && promo.targets.products.length > 0 && (
              <Grid size={12}>
                <Typography variant="body2">Produtos: {promo.targets.products.map((p) => p.name).join(', ')}</Typography>
              </Grid>
            )}
          </>
        )}
        <Grid size={12}><RHFSwitch control={control} name="is_active" label="Ativa" /></Grid>
        {promo && <Grid size={12}><PromoPreview promo={promo} /></Grid>}
      </Grid>
    </FormDialog>
  );
}

export default function PromotionsPage() {
  const canManage = useCan('promotions.manage');
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: '-starts_at' });
  const q = usePromotions(apiParams);
  const [editing, setEditing] = useState<Promotion | 'new' | null>(null);
  const remove = useRemoveWithConfirm<Promotion>({ remove: (p) => deletePromotion(p.id), invalidate: ['admin', 'promotions'], successMessage: 'Promoção excluída' });
  const columns: Column<Promotion>[] = [
    { key: 'name', header: 'Nome', sortKey: 'name', render: (p) => p.name },
    { key: 'type', header: 'Desconto', render: (p) => (p.discount_type === 'percent' ? formatBp(p.value) : `${formatBRL(p.value)} /un. de venda`) },
    { key: 'target', header: 'Alvo', render: (p) => (p.scope === 'all' ? 'Todo o catálogo' : [...p.targets.categories.map((c) => c.name), ...p.targets.brands.map((b) => b.name), ...p.targets.products.map((x) => x.name)].join(', ') || '—') },
    { key: 'period', header: 'Vigência', sortKey: 'starts_at', render: (p) => `${formatDateTime(p.starts_at)} → ${p.ends_at ? formatDateTime(p.ends_at) : 'sem fim'}` },
    { key: 'status', header: 'Status', render: (p) => <LabelChip label={PROMOTION_STATUS[p.status].label} color={PROMOTION_STATUS[p.status].color} /> },
    { key: 'act', header: '', align: 'right', render: (p) => canManage && <Button size="small" color="error" onClick={(e) => { e.stopPropagation(); remove.ask(p); }}>Excluir</Button> },
  ];
  return (
    <>
      <PageHeader title="Promoções" count={q.data?.meta.total} actions={canManage && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Nova promoção</Button>} />
      <ListToolbar q={params.q} onSearch={(v) => update({ q: v })} placeholder="Buscar promoção…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Status" value={params.filters.status ?? ''} onChange={(v) => update({ status: v })} options={Object.entries(PROMOTION_STATUS).map(([value, s]) => ({ value, label: s.label }))} width={160} />
      </ListToolbar>
      <DataTable caption="Promoções" columns={columns} rows={q.data?.data} rowKey={(p) => p.id} meta={q.data?.meta} loading={q.isPending} fetching={q.isFetching} error={q.error} onRetry={() => void q.refetch()} sort={params.sort} onSortChange={(sort) => update({ sort })} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} onRowClick={(p) => setEditing(p)} emptyTitle="Nenhuma promoção cadastrada" filtered={activeFilterCount > 0} onClearFilters={clear} />
      {editing && <PromotionDialog promo={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir a promoção "${remove.target?.name ?? ''}"?`} confirmLabel="Excluir promoção" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
