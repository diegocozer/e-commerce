import AddIcon from '@mui/icons-material/AddOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, Chip } from '@mui/material';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import type { PriceList } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { PRICE_LIST_KIND } from '@/shared/formatters/labels';
import { formatBp } from '@/shared/formatters/money';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { deletePriceList, usePriceLists, useSavePriceList } from '../api';
import { RHFPercentField } from '@/shared/ui/form/PercentField';
import { priceListSchema, type PriceListForm } from '../schemas';

export function PriceListDialog({ list, onClose }: { list: PriceList | null; onClose: () => void }) {
  const save = useSavePriceList(list?.id ?? null);
  const { control, handleSubmit, setError } = useForm<PriceListForm>({
    resolver: zodResolver(priceListSchema),
    defaultValues: { code: list?.code ?? '', name: list?.name ?? '', kind: list?.kind ?? 'custom', discount_bp: list?.discount_bp ?? null, is_default: list?.is_default ?? false, is_active: list?.is_active ?? true },
  });
  const { error, run } = useFormSubmit(setError);
  const onSubmit = handleSubmit(async (v) => {
    const body: Record<string, unknown> = { ...v };
    if (list) delete body.code; // código imutável após criação
    if (await run(() => save.mutateAsync(body))) {
      notify.success('Tabela de preço salva');
      onClose();
    }
  });
  return (
    <FormDialog open title={list ? `Editar tabela: ${list.name}` : 'Nova tabela de preço'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error}>
      <RHFTextField control={control} name="code" label="Código" required disabled={!!list} helperText="Ex.: atacado. Não pode ser alterado depois." />
      <RHFTextField control={control} name="name" label="Nome" required />
      <RHFSelect control={control} name="kind" label="Tipo" options={Object.entries(PRICE_LIST_KIND).map(([value, label]) => ({ value, label }))} />
      <RHFPercentField control={control} name="discount_bp" label="Desconto sobre o preço base" helperText="Opcional. Vazio = só preços/faixas da tabela." />
      <RHFSwitch control={control} name="is_default" label="Tabela padrão (desmarca a anterior)" />
      <RHFSwitch control={control} name="is_active" label="Ativa" />
    </FormDialog>
  );
}

export default function PriceListsPage() {
  const q = usePriceLists();
  const navigate = useNavigate();
  const canManage = useCan('pricing.manage');
  const [editing, setEditing] = useState<PriceList | 'new' | null>(null);
  const remove = useRemoveWithConfirm<PriceList>({ remove: (p) => deletePriceList(p.id), invalidate: ['admin', 'price-lists'], successMessage: 'Tabela excluída' });
  const columns: Column<PriceList>[] = [
    { key: 'name', header: 'Nome', render: (p) => <>{p.name} {p.is_default && <Chip size="small" color="primary" label="Padrão" />}</> },
    { key: 'code', header: 'Código', render: (p) => p.code },
    { key: 'kind', header: 'Tipo', render: (p) => PRICE_LIST_KIND[p.kind] },
    { key: 'disc', header: 'Regra', render: (p) => (p.discount_bp ? `${formatBp(p.discount_bp)} sobre a base` : 'Preço por variante') },
    { key: 'tiers', header: 'Faixas', align: 'right', render: (p) => p.tiers_count },
    { key: 'assigned', header: 'Atribuída a', render: (p) => `${p.customers_count} clientes · ${p.companies_count} empresas` },
    { key: 'status', header: 'Status', render: (p) => <ActiveChip active={p.is_active} on="Ativa" off="Inativa" /> },
    {
      key: 'act',
      header: '',
      align: 'right',
      render: (p) =>
        canManage && (
          <span onClick={(e) => e.stopPropagation()}>
            <Button size="small" onClick={() => setEditing(p)}>Editar</Button>
            <Button size="small" color="error" onClick={() => remove.ask(p)}>Excluir</Button>
          </span>
        ),
    },
  ];
  return (
    <>
      <PageHeader title="Tabelas de preço" actions={canManage && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Nova tabela</Button>} />
      <Alert severity="info" sx={{ mb: 3 }}>
        O cliente paga sempre o <strong>menor</strong> preço entre base, tabela, promoção e preço específico (cupom aplicado depois).
      </Alert>
      <DataTable caption="Tabelas de preço" columns={columns} rows={q.data} rowKey={(p) => p.id} loading={q.isPending} error={q.error} onRetry={() => void q.refetch()} onRowClick={(p) => navigate(`/precos/tabelas/${p.id}`)} emptyTitle="Nenhuma tabela cadastrada" />
      {editing && <PriceListDialog list={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir a tabela "${remove.target?.name ?? ''}"?`} description="Tabelas padrão ou atribuídas a clientes/empresas não podem ser excluídas." confirmLabel="Excluir tabela" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
