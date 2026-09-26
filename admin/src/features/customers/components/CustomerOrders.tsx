import { useNavigate } from 'react-router-dom';
import { useOrders } from '@/features/orders/api';
import type { AdminOrderListItem } from '@/shared/api/types';
import { formatDateTime } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { DataTable, OrderStatusChip, type Column } from '@/shared/ui';

const columns: Column<AdminOrderListItem>[] = [
  { key: 'n', header: 'Número', render: (o) => o.number },
  { key: 'd', header: 'Data', render: (o) => formatDateTime(o.placed_at) },
  { key: 't', header: 'Total', align: 'right', render: (o) => formatBRL(o.total_cents) },
  { key: 's', header: 'Status', render: (o) => <OrderStatusChip status={o.status} /> },
];

export function CustomerOrders({ customerId }: { customerId: number }) {
  const navigate = useNavigate();
  const q = useOrders({ customer_id: customerId, per_page: 25 });
  return <DataTable columns={columns} rows={q.data?.data} rowKey={(o) => o.id} loading={q.isPending} error={q.error} onRetry={() => void q.refetch()} onRowClick={(o) => navigate(`/pedidos/${o.id}`)} emptyTitle="Nenhum pedido" />;
}
