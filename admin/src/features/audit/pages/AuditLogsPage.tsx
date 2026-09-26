import CloseIcon from '@mui/icons-material/CloseOutlined';
import { Box, Drawer, IconButton, Link, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import type { FailedJob } from '@/shared/api/extraTypes';
import type { AuditLog } from '@/shared/api/types';
import { formatDateTime } from '@/shared/formatters/date';
import { ACTOR_TYPE } from '@/shared/formatters/labels';
import { useListParams } from '@/shared/hooks/useListParams';
import { DataTable, FilterSelect, KeyValue, ListToolbar, PageHeader, type Column } from '@/shared/ui';
import { useAuditLogs, useFailedJobs } from '../api';
import { DiffViewer } from '../components/DiffViewer';

const ENTITY_ROUTE: Record<string, string> = { order: '/pedidos', product: '/produtos', customer: '/clientes', company: '/empresas', shipping_zone: '/frete/zonas' };
const entityLink = (a: AuditLog) =>
  a.auditable_type && a.auditable_id && ENTITY_ROUTE[a.auditable_type] ? (
    <Link component={RouterLink} to={`${ENTITY_ROUTE[a.auditable_type]}/${a.auditable_id}`} onClick={(e) => e.stopPropagation()}>
      {a.auditable_label ?? `#${a.auditable_id}`}
    </Link>
  ) : (
    (a.auditable_label ?? (a.auditable_id ? `#${a.auditable_id}` : '—'))
  );

const columns: Column<AuditLog>[] = [
  { key: 'date', header: 'Data/hora', sortKey: 'created_at', render: (a) => formatDateTime(a.created_at) },
  { key: 'actor', header: 'Ator', render: (a) => `${ACTOR_TYPE[a.actor.type]}${a.actor.name ? ` · ${a.actor.name}` : ''}` },
  { key: 'action', header: 'Ação', render: (a) => <code>{a.action}</code> },
  { key: 'entity', header: 'Entidade', render: (a) => <>{a.auditable_type ?? '—'} · {entityLink(a)}</> },
  { key: 'ip', header: 'IP', hideBelow: 'md', render: (a) => a.ip ?? '—' },
  { key: 'rid', header: 'Request id', hideBelow: 'lg', render: (a) => (a.request_id ? <code>{a.request_id}</code> : '—') },
];

const jobColumns: Column<FailedJob>[] = [
  { key: 'date', header: 'Falhou em', render: (j) => formatDateTime(j.failed_at) },
  { key: 'queue', header: 'Fila', render: (j) => j.queue },
  { key: 'job', header: 'Job', render: (j) => j.job },
  { key: 'exc', header: 'Erro', render: (j) => j.exception_summary },
];

export default function AuditLogsPage() {
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: '-created_at' });
  const [tab, setTab] = useState(0);
  const q = useAuditLogs(apiParams);
  const jobs = useFailedJobs({ per_page: 25 }, tab === 1);
  const [selected, setSelected] = useState<AuditLog | null>(null);
  return (
    <>
      <PageHeader title="Logs de auditoria" subtitle="Somente leitura. Dados sensíveis aparecem como ••••." />
      <Tabs value={tab} onChange={(_, v: number) => setTab(v)} sx={{ mb: 3, borderBottom: 1, borderColor: 'divider' }}>
        <Tab label="Auditoria" />
        <Tab label="Jobs com falha" />
      </Tabs>
      {tab === 0 ? (
        <>
          <ListToolbar q={params.filters.request_id ?? ''} onSearch={(v) => update({ request_id: v })} placeholder="Request id…" onClear={clear} activeCount={activeFilterCount}>
            <FilterSelect label="Ator" value={params.filters.actor_type ?? ''} onChange={(v) => update({ actor_type: v })} options={Object.entries(ACTOR_TYPE).map(([value, label]) => ({ value, label }))} width={140} />
            <TextField label="Ação (prefixo)" value={params.filters.action ?? ''} onChange={(e) => update({ action: e.target.value })} placeholder="order." sx={{ width: { md: 160 } }} />
            <TextField label="Tipo de entidade" value={params.filters.auditable_type ?? ''} onChange={(e) => update({ auditable_type: e.target.value })} placeholder="product" sx={{ width: { md: 160 } }} />
            <TextField label="Id da entidade" value={params.filters.auditable_id ?? ''} onChange={(e) => update({ auditable_id: e.target.value.replace(/\D/g, '') })} sx={{ width: { md: 120 } }} />
            <TextField type="date" label="De" value={params.filters.date_from ?? ''} onChange={(e) => update({ date_from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 150 } }} />
            <TextField type="date" label="Até" value={params.filters.date_to ?? ''} onChange={(e) => update({ date_to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 150 } }} />
          </ListToolbar>
          <DataTable caption="Registros de auditoria" columns={columns} rows={q.data?.data} rowKey={(a) => a.id} meta={q.data?.meta} loading={q.isPending} fetching={q.isFetching} error={q.error} onRetry={() => void q.refetch()} sort={params.sort} onSortChange={(sort) => update({ sort })} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} onRowClick={setSelected} emptyTitle="Nenhum registro" filtered={activeFilterCount > 0} onClearFilters={clear} />
        </>
      ) : (
        <DataTable caption="Jobs com falha" columns={jobColumns} rows={jobs.data?.data} rowKey={(j) => j.uuid} meta={jobs.data?.meta} loading={jobs.isPending} error={jobs.error} onRetry={() => void jobs.refetch()} emptyTitle="Nenhum job com falha" />
      )}
      <Drawer anchor="right" open={!!selected} onClose={() => setSelected(null)} slotProps={{ paper: { sx: { width: { xs: '100%', md: 640 } } } }}>
        {selected && (
          <Box sx={{ p: 5 }} role="region" aria-label="Detalhe do registro">
            <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
              <Typography variant="h2"><code>{selected.action}</code></Typography>
              <IconButton aria-label="Fechar" onClick={() => setSelected(null)}><CloseIcon /></IconButton>
            </Stack>
            <KeyValue
              items={[
                { label: 'Data/hora', value: formatDateTime(selected.created_at) },
                { label: 'Ator', value: `${ACTOR_TYPE[selected.actor.type]}${selected.actor.name ? ` · ${selected.actor.name}` : ''}` },
                { label: 'Entidade', value: <>{selected.auditable_type ?? '—'} · {entityLink(selected)}</> },
                { label: 'IP', value: selected.ip ?? '—' },
                { label: 'User agent', value: selected.user_agent ?? '—' },
                { label: 'Request id', value: selected.request_id ?? '—' },
              ]}
            />
            <Typography variant="h3" sx={{ mt: 4, mb: 2 }}>Alterações</Typography>
            <DiffViewer oldValues={selected.old_values} newValues={selected.new_values} />
          </Box>
        )}
      </Drawer>
    </>
  );
}
