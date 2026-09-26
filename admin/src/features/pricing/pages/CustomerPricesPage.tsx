import AddIcon from '@mui/icons-material/AddOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Button, FormControlLabel, Radio, RadioGroup } from '@mui/material';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import type { AdminCompany, AdminCustomer, AdminVariantPickerItem, CustomerPrice } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { formatDate, isoToLocalInput, localInputToIso } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ConfirmDialog, DataTable, notify, PageHeader, VariantPicker, type Column } from '@/shared/ui';
import { FormDialog, RHFDateTimeField, RHFMoneyField } from '@/shared/ui/form';
import { deleteCustomerPrice, useCustomerPrices, useSaveCustomerPrice } from '../api';
import { EntityAutocomplete } from '../components/EntityAutocomplete';
import { customerPriceSchema, type CustomerPriceForm } from '../schemas';

function CustomerPriceDialog({ row, onClose }: { row: CustomerPrice | null; onClose: () => void }) {
  const save = useSaveCustomerPrice(row?.id ?? null);
  const [variant, setVariant] = useState<AdminVariantPickerItem | null>(row?.variant ?? null);
  const [customerOpt, setCustomerOpt] = useState(row?.customer ? { id: row.customer.id, label: row.customer.name } : null);
  const [companyOpt, setCompanyOpt] = useState(row?.company ? { id: row.company.id, label: row.company.legal_name } : null);
  const { control, handleSubmit, setError, setValue, formState } = useForm<CustomerPriceForm>({
    resolver: zodResolver(customerPriceSchema),
    defaultValues: {
      target: row?.company ? 'company' : 'customer', customer_id: row?.customer?.id ?? null, company_id: row?.company?.id ?? null, variant_id: row?.variant.id ?? null,
      price_cents: row?.price_cents ?? null, starts_at: isoToLocalInput(row?.starts_at), ends_at: isoToLocalInput(row?.ends_at),
    },
  });
  const target = useWatch({ control, name: 'target' });
  const { error, run } = useFormSubmit(setError);
  const onSubmit = handleSubmit(async (v) => {
    const dates = { price_cents: v.price_cents, starts_at: localInputToIso(v.starts_at), ends_at: localInputToIso(v.ends_at) };
    const body = row ? dates : { ...dates, variant_id: v.variant_id, ...(v.target === 'customer' ? { customer_id: v.customer_id } : { company_id: v.company_id }) };
    if (await run(() => save.mutateAsync(body))) {
      notify.success('Preço específico salvo');
      onClose();
    }
  });
  return (
    <FormDialog open title={row ? 'Editar preço específico' : 'Novo preço específico'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error}>
      {!row && (
        <>
          <Controller control={control} name="target" render={({ field }) => (
            <RadioGroup row value={field.value} onChange={(e) => field.onChange(e.target.value)}>
              <FormControlLabel value="customer" control={<Radio />} label="Cliente" />
              <FormControlLabel value="company" control={<Radio />} label="Empresa" />
            </RadioGroup>
          )} />
          {target === 'customer' ? (
            <EntityAutocomplete<AdminCustomer> path="/admin/customers" label="Cliente" required value={customerOpt} toOption={(c) => ({ id: c.id, label: `${c.name} (${c.email})` })} onChange={(o) => { setCustomerOpt(o); setValue('customer_id', o?.id ?? null, { shouldValidate: formState.isSubmitted }); }} error={formState.errors.customer_id?.message} />
          ) : (
            <EntityAutocomplete<AdminCompany> path="/admin/companies" label="Empresa" required value={companyOpt} toOption={(c) => ({ id: c.id, label: `${c.legal_name} (${c.cnpj_masked})` })} onChange={(o) => { setCompanyOpt(o); setValue('company_id', o?.id ?? null, { shouldValidate: formState.isSubmitted }); }} error={formState.errors.company_id?.message} />
          )}
          <VariantPicker value={variant} required onChange={(v) => { setVariant(v); setValue('variant_id', v?.id ?? null, { shouldValidate: formState.isSubmitted }); }} error={!!formState.errors.variant_id} helperText={formState.errors.variant_id?.message ?? (variant ? `Preço base ${formatBRL(variant.price_cents)}` : undefined)} />
        </>
      )}
      <RHFMoneyField control={control} name="price_cents" label="Preço específico" required />
      <RHFDateTimeField control={control} name="starts_at" label="Início da vigência (opcional)" />
      <RHFDateTimeField control={control} name="ends_at" label="Fim da vigência (opcional)" />
    </FormDialog>
  );
}

export default function CustomerPricesPage() {
  const canManage = useCan('pricing.manage');
  const { apiParams, update } = useListParams();
  const q = useCustomerPrices(apiParams);
  const [editing, setEditing] = useState<CustomerPrice | 'new' | null>(null);
  const remove = useRemoveWithConfirm<CustomerPrice>({ remove: (r) => deleteCustomerPrice(r.id), invalidate: ['admin', 'customer-prices'], successMessage: 'Preço removido' });
  const columns: Column<CustomerPrice>[] = [
    { key: 'who', header: 'Cliente / empresa', render: (r) => r.customer?.name ?? r.company?.legal_name ?? '—' },
    { key: 'variant', header: 'Variante', render: (r) => `${r.variant.sku} — ${r.variant.product.name}` },
    { key: 'base', header: 'Preço base', align: 'right', render: (r) => formatBRL(r.variant.price_cents) },
    { key: 'price', header: 'Preço específico', align: 'right', render: (r) => formatBRL(r.price_cents) },
    { key: 'valid', header: 'Vigência', render: (r) => (r.starts_at || r.ends_at ? `${r.starts_at ? formatDate(r.starts_at) : '—'} a ${r.ends_at ? formatDate(r.ends_at) : 'sem fim'}` : 'Sempre') },
    { key: 'by', header: 'Criado por', hideBelow: 'md', render: (r) => r.created_by?.name ?? '—' },
    {
      key: 'act',
      header: '',
      align: 'right',
      render: (r) =>
        canManage && (
          <span>
            <Button size="small" onClick={() => setEditing(r)}>Editar</Button>
            <Button size="small" color="error" onClick={() => remove.ask(r)}>Excluir</Button>
          </span>
        ),
    },
  ];
  return (
    <>
      <PageHeader title="Preços por cliente" count={q.data?.meta.total} actions={canManage && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Novo preço</Button>} />
      <DataTable caption="Preços por cliente" columns={columns} rows={q.data?.data} rowKey={(r) => r.id} meta={q.data?.meta} loading={q.isPending} fetching={q.isFetching} error={q.error} onRetry={() => void q.refetch()} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} emptyTitle="Nenhum preço específico" />
      {editing && <CustomerPriceDialog row={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title="Excluir este preço específico?" description="O cliente volta a pagar o menor preço entre base, tabela e promoção." confirmLabel="Excluir preço" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
