import AddIcon from '@mui/icons-material/AddOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Avatar, Button, IconButton } from '@mui/material';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import type { AdminBrand } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { formatDate } from '@/shared/formatters/date';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, FilterSelect, ListToolbar, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { deleteBrand, uploadBrandLogo, useBrands, useSaveBrand } from '../api';
import { brandSchema, type BrandForm } from '../schemas';

function BrandDialog({ brand, onClose }: { brand: AdminBrand | null; onClose: () => void }) {
  const save = useSaveBrand(brand?.id ?? null);
  const { control, handleSubmit, setError } = useForm<BrandForm>({ resolver: zodResolver(brandSchema), defaultValues: { name: brand?.name ?? '', slug: brand?.slug ?? '', is_active: brand?.is_active ?? true } });
  const { error, run } = useFormSubmit(setError);
  const [logo, setLogo] = useState<File | null>(null);
  const onSubmit = handleSubmit(async (v) => {
    const ok = await run(async () => {
      const res = await save.mutateAsync({ ...v, slug: v.slug || undefined });
      if (logo) await uploadBrandLogo(res.data.id, logo);
    });
    if (ok) {
      notify.success('Marca salva');
      onClose();
    }
  });
  return (
    <FormDialog open title={brand ? `Editar marca: ${brand.name}` : 'Nova marca'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} maxWidth="xs">
      <RHFTextField control={control} name="name" label="Nome" required autoFocus />
      <RHFTextField control={control} name="slug" label="Slug" helperText="Vazio = gerado do nome" />
      <Button component="label" variant="outlined">
        {logo ? logo.name : 'Enviar logo (JPG, PNG, WebP até 5 MB)'}
        <input type="file" hidden accept="image/jpeg,image/png,image/webp" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} />
      </Button>
      <RHFSwitch control={control} name="is_active" label="Ativa" />
    </FormDialog>
  );
}

export default function BrandsPage() {
  const canManage = useCan('products.manage');
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: 'name' });
  const list = useBrands(apiParams);
  const [editing, setEditing] = useState<AdminBrand | null | 'new'>(null);
  const remove = useRemoveWithConfirm<AdminBrand>({ remove: (b) => deleteBrand(b.id), invalidate: ['admin', 'brands'], successMessage: 'Marca excluída' });
  const columns: Column<AdminBrand>[] = [
    { key: 'logo', header: 'Logo', width: 56, render: (b) => <Avatar variant="rounded" src={b.logo_url ?? undefined} alt="">{b.name[0]}</Avatar> },
    { key: 'name', header: 'Nome', sortKey: 'name', render: (b) => b.name },
    { key: 'slug', header: 'Slug', hideBelow: 'md', render: (b) => b.slug },
    { key: 'products', header: 'Produtos', align: 'right', render: (b) => b.products_count },
    { key: 'created', header: 'Criada em', sortKey: 'created_at', hideBelow: 'md', render: (b) => formatDate(b.created_at) },
    { key: 'status', header: 'Status', render: (b) => <ActiveChip active={b.is_active} on="Ativa" off="Inativa" /> },
    {
      key: 'actions',
      header: '',
      align: 'right',
      render: (b) =>
        canManage && (
          <IconButton aria-label={`Excluir ${b.name}`} onClick={(e) => { e.stopPropagation(); remove.ask(b); }}>
            <DeleteIcon fontSize="small" />
          </IconButton>
        ),
    },
  ];
  return (
    <>
      <PageHeader title="Marcas" count={list.data?.meta.total} actions={canManage && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Nova marca</Button>} />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Buscar marca…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Status" value={params.filters.is_active ?? ''} onChange={(v) => update({ is_active: v })} options={[{ value: '1', label: 'Ativas' }, { value: '0', label: 'Inativas' }]} width={140} />
      </ListToolbar>
      <DataTable
        caption="Marcas"
        columns={columns}
        rows={list.data?.data}
        rowKey={(b) => b.id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        onRowClick={canManage ? (b) => setEditing(b) : undefined}
        emptyTitle="Nenhuma marca cadastrada"
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
      {editing && <BrandDialog brand={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir a marca "${remove.target?.name ?? ''}"?`} description="Os produtos mantêm a marca no histórico." confirmLabel="Excluir marca" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
