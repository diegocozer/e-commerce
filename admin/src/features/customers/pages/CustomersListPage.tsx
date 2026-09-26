import { Chip, TextField } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import { usePriceLists } from '@/shared/api/lookups';
import type { AdminCustomer } from '@/shared/api/types';
import { formatDate } from '@/shared/formatters/date';
import { formatPhone } from '@/shared/formatters/document';
import { CUSTOMER_TYPE } from '@/shared/formatters/labels';
import { formatBRL } from '@/shared/formatters/money';
import { useListParams } from '@/shared/hooks/useListParams';
import { ActiveChip, DataTable, FilterSelect, ListToolbar, PageHeader, type Column } from '@/shared/ui';
import { useCustomers } from '../api';

const columns: Column<AdminCustomer>[] = [
  { key: 'name', header: 'Nome', sortKey: 'name', render: (c) => c.name },
  { key: 'type', header: 'Tipo', render: (c) => <Chip size="small" variant="outlined" label={CUSTOMER_TYPE[c.type]} /> },
  { key: 'doc', header: 'CPF/CNPJ', hideBelow: 'md', render: (c) => <span className="num">{c.type === 'company' ? (c.company?.cnpj_masked ?? '—') : (c.cpf_masked ?? '—')}</span> },
  { key: 'email', header: 'E-mail', hideBelow: 'md', render: (c) => c.email },
  { key: 'phone', header: 'Telefone', hideBelow: 'lg', render: (c) => formatPhone(c.phone) },
  { key: 'company', header: 'Empresa', hideBelow: 'lg', render: (c) => c.company?.trade_name ?? c.company?.legal_name ?? '—' },
  { key: 'pl', header: 'Tabela', hideBelow: 'lg', render: (c) => c.effective_price_list?.name ?? 'Varejo' },
  { key: 'orders', header: 'Pedidos', align: 'right', render: (c) => c.stats.orders_count },
  { key: 'spent', header: 'Total comprado', align: 'right', sortKey: 'total_spent', render: (c) => formatBRL(c.stats.total_spent_cents) },
  { key: 'created', header: 'Cadastro', sortKey: 'created_at', hideBelow: 'md', render: (c) => formatDate(c.created_at) },
  { key: 'status', header: 'Status', render: (c) => (c.anonymized_at ? <Chip size="small" label="Anonimizado" /> : <ActiveChip active={c.is_active} on="Ativo" off="Bloqueado" />) },
];

export default function CustomersListPage() {
  const navigate = useNavigate();
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: '-created_at' });
  const list = useCustomers(apiParams);
  const priceLists = usePriceLists().data ?? [];
  return (
    <>
      <PageHeader title="Clientes" count={list.data?.meta.total} />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Nome, e-mail, CPF/CNPJ…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Tipo" value={params.filters.type ?? ''} onChange={(v) => update({ type: v })} options={[{ value: 'individual', label: 'Pessoa física' }, { value: 'company', label: 'Pessoa jurídica' }]} width={160} />
        <FilterSelect label="Status" value={params.filters.is_active ?? ''} onChange={(v) => update({ is_active: v })} options={[{ value: '1', label: 'Ativos' }, { value: '0', label: 'Bloqueados' }]} width={140} />
        <FilterSelect label="Tabela" value={params.filters.price_list_id ?? ''} onChange={(v) => update({ price_list_id: v })} options={priceLists.map((p) => ({ value: String(p.id), label: p.name }))} width={160} />
        <TextField type="date" label="Cadastro de" value={params.filters.date_from ?? ''} onChange={(e) => update({ date_from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
        <TextField type="date" label="até" value={params.filters.date_to ?? ''} onChange={(e) => update({ date_to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
      </ListToolbar>
      <DataTable
        caption="Clientes"
        columns={columns}
        rows={list.data?.data}
        rowKey={(c) => c.id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        onRowClick={(c) => navigate(`/clientes/${c.id}`)}
        emptyTitle="Nenhum cliente cadastrado"
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
    </>
  );
}
