import { useNavigate } from 'react-router-dom';
import { usePriceLists } from '@/shared/api/lookups';
import type { AdminCompany } from '@/shared/api/types';
import { useListParams } from '@/shared/hooks/useListParams';
import { DataTable, FilterSelect, ListToolbar, PageHeader, type Column } from '@/shared/ui';
import { useCompanies } from '../api';

const columns: Column<AdminCompany>[] = [
  { key: 'cnpj', header: 'CNPJ', render: (c) => <span className="num">{c.cnpj_masked}</span> },
  { key: 'legal', header: 'Razão social', render: (c) => c.legal_name },
  { key: 'trade', header: 'Nome fantasia', hideBelow: 'md', render: (c) => c.trade_name ?? '—' },
  { key: 'ie', header: 'IE', hideBelow: 'md', render: (c) => (c.state_registration_exempt ? 'Isento' : (c.state_registration ?? '—')) },
  { key: 'pl', header: 'Tabela de preço', render: (c) => c.price_list?.name ?? 'Padrão' },
  { key: 'users', header: 'Usuários', align: 'right', render: (c) => c.customers.length },
];

export default function CompaniesListPage() {
  const navigate = useNavigate();
  const { params, apiParams, update, clear, activeFilterCount } = useListParams();
  const list = useCompanies(apiParams);
  const priceLists = usePriceLists().data ?? [];
  return (
    <>
      <PageHeader title="Empresas" count={list.data?.meta.total} />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Razão social, fantasia ou CNPJ…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Tabela" value={params.filters.price_list_id ?? ''} onChange={(v) => update({ price_list_id: v })} options={priceLists.map((p) => ({ value: String(p.id), label: p.name }))} width={160} />
      </ListToolbar>
      <DataTable
        caption="Empresas"
        columns={columns}
        rows={list.data?.data}
        rowKey={(c) => c.id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        onRowClick={(c) => navigate(`/empresas/${c.id}`)}
        emptyTitle="Nenhuma empresa cadastrada"
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
    </>
  );
}
