import { Chip, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import type { AdminOrderListItem } from '@/shared/api/types';
import { formatDateTime } from '@/shared/formatters/date';
import { CUSTOMER_TYPE, PAYMENT_STATUS, SHIPPING_METHOD_TYPE } from '@/shared/formatters/labels';
import { formatBRL } from '@/shared/formatters/money';
import { useListParams } from '@/shared/hooks/useListParams';
import { DataTable, FilterSelect, ListToolbar, OrderStatusChip, PageHeader, PaymentStatusChip, type Column } from '@/shared/ui';
import { useOrders, useOrderStatusCounts } from '../api';

/** Abas rápidas (UX §5.8). `value` = valor do filtro `status` (lista) ou especial. */
const TABS: { value: string; label: string; count: (c: Record<string, number>) => number }[] = [
  { value: '', label: 'Todos', count: (c) => c.all },
  { value: 'pending_payment', label: 'Aguardando pagamento', count: (c) => c.pending_payment },
  { value: 'paid', label: 'Para separar', count: (c) => c.paid },
  { value: 'processing', label: 'Em separação', count: (c) => c.processing },
  { value: 'ready_for_pickup', label: 'Prontos p/ retirada', count: (c) => c.ready_for_pickup },
  { value: 'shipped', label: 'Enviados', count: (c) => c.shipped },
  { value: 'delivered,picked_up', label: 'Concluídos', count: (c) => c.delivered + c.picked_up },
  { value: 'cancelled', label: 'Cancelados', count: (c) => c.cancelled },
  { value: 'cancellation_requested', label: 'Solicitações de cancelamento', count: (c) => c.cancellation_requests },
];

const columns: Column<AdminOrderListItem>[] = [
  { key: 'number', header: 'Número', render: (o) => <strong>{o.number}</strong> },
  { key: 'placed_at', header: 'Data', sortKey: 'placed_at', render: (o) => formatDateTime(o.placed_at) },
  {
    key: 'customer',
    header: 'Cliente',
    render: (o) => (
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
        <span>{o.customer.company_name ?? o.customer.name}</span>
        <Chip size="small" variant="outlined" label={CUSTOMER_TYPE[o.customer.type]} />
        {o.has_cancellation_request && <Chip size="small" color="warning" label="Pediu cancelamento" />}
      </Stack>
    ),
  },
  { key: 'items', header: 'Itens', align: 'right', hideBelow: 'md', render: (o) => o.items_count },
  { key: 'total', header: 'Total', align: 'right', sortKey: 'total_cents', render: (o) => formatBRL(o.total_cents) },
  { key: 'payment', header: 'Pagamento', hideBelow: 'md', render: (o) => <PaymentStatusChip status={o.payment_status} /> },
  {
    key: 'shipping',
    header: 'Entrega',
    hideBelow: 'lg',
    render: (o) => (
      <>
        {o.shipping_method_name}
        {o.shipping_city && (
          <Typography variant="caption" component="div" color="text.secondary">
            {o.shipping_city}/{o.shipping_state}
          </Typography>
        )}
      </>
    ),
  },
  { key: 'status', header: 'Status', render: (o) => <OrderStatusChip status={o.status} /> },
];

export default function OrdersListPage() {
  const navigate = useNavigate();
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: '-placed_at' });
  const tab = params.filters.cancellation_requested === '1' ? 'cancellation_requested' : (params.filters.status ?? '');
  const { status: _s, cancellation_requested: _c, ...countFilters } = params.filters;
  const counts = useOrderStatusCounts({ q: params.q || undefined, ...countFilters });
  const list = useOrders(apiParams);

  return (
    <>
      <PageHeader title="Pedidos" count={list.data?.meta.total} />
      <Tabs
        value={TABS.some((t) => t.value === tab) ? tab : false}
        onChange={(_, v: string) =>
          v === 'cancellation_requested' ? update({ status: null, cancellation_requested: '1' }) : update({ status: v || null, cancellation_requested: null })
        }
        variant="scrollable"
        allowScrollButtonsMobile
        sx={{ mb: 3, borderBottom: 1, borderColor: 'divider' }}
        aria-label="Filtrar por status"
      >
        {TABS.map((t) => (
          <Tab
            key={t.value || 'all'}
            value={t.value}
            label={
              <span>
                {t.label}{' '}
                {counts.data && (
                  <Typography component="span" variant="caption" className="num" color="text.secondary">
                    ({t.count(counts.data as unknown as Record<string, number>)})
                  </Typography>
                )}
              </span>
            }
          />
        ))}
      </Tabs>
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Número, cliente, CPF/CNPJ ou e-mail" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect
          label="Pagamento"
          value={params.filters.payment_status ?? ''}
          onChange={(v) => update({ payment_status: v })}
          options={Object.entries(PAYMENT_STATUS).map(([value, s]) => ({ value, label: s.label }))}
        />
        <FilterSelect
          label="Entrega"
          value={params.filters.shipping_method_type ?? ''}
          onChange={(v) => update({ shipping_method_type: v })}
          options={Object.entries(SHIPPING_METHOD_TYPE).map(([value, label]) => ({ value, label }))}
        />
        <TextField type="date" label="De" value={params.filters.date_from ?? ''} onChange={(e) => update({ date_from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
        <TextField type="date" label="Até" value={params.filters.date_to ?? ''} onChange={(e) => update({ date_to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
      </ListToolbar>
      <DataTable
        caption="Lista de pedidos"
        columns={columns}
        rows={list.data?.data}
        rowKey={(o) => o.id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        onRowClick={(o) => navigate(`/pedidos/${o.id}`)}
        emptyTitle="Nenhum pedido ainda"
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
    </>
  );
}
