import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControl from '@mui/material/FormControl';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Select from '@mui/material/Select';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { Link as RouterLink, useSearchParams } from 'react-router';
import type { OrderStatus } from '@/shared/api/types';
import { formatDate } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { EmptyState } from '@/shared/ui/EmptyState';
import { ErrorState } from '@/shared/ui/ErrorState';
import { ORDER_STATUS_LABEL, OrderStatusChip } from '@/shared/ui/OrderStatusChip';
import { PageHeading } from '@/shared/ui/PageHeading';
import { useOrders } from '../hooks/queries';
import { useReorder } from '../hooks/useReorder';

export default function OrdersPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('pagina')) || 1);
  const statusRaw = params.get('status') as OrderStatus | null;
  const status = statusRaw && statusRaw in ORDER_STATUS_LABEL ? statusRaw : null;
  const q = useOrders(page, status ? [status] : null);
  const reorder = useReorder();
  const set = (k: string, v: string | null) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    if (k !== 'pagina') next.delete('pagina');
    setParams(next);
  };
  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 2 }}>
        <PageHeading>Pedidos</PageHeading>
        <FormControl size="small" sx={{ minWidth: 220 }}>
          <InputLabel id="status-filter">Status</InputLabel>
          <Select labelId="status-filter" label="Status" value={status ?? ''} onChange={(e) => set('status', e.target.value || null)}>
            <MenuItem value="">Todos</MenuItem>
            {(Object.keys(ORDER_STATUS_LABEL) as OrderStatus[]).map((s) => <MenuItem key={s} value={s}>{ORDER_STATUS_LABEL[s]}</MenuItem>)}
          </Select>
        </FormControl>
      </Box>
      {q.isLoading ? (
        <Box aria-busy="true">{[0, 1, 2].map((i) => <Skeleton key={i} variant="rectangular" height={110} sx={{ mb: 3, borderRadius: 3 }} />)}</Box>
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : !q.data?.data.length ? (
        <EmptyState
          icon={<Inventory2Outlined />}
          title={status ? 'Nenhum pedido com este status.' : 'Nenhum pedido ainda.'}
          action={status ? <Button onClick={() => set('status', null)}>Limpar</Button> : <Button component={RouterLink} to="/">Explorar categorias</Button>}
        />
      ) : (
        <>
          <Stack spacing={3} component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }}>
            {q.data.data.map((o) => (
              <Card component="li" key={o.uuid} sx={{ p: 4 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 2 }}>
                  <Typography sx={{ fontWeight: 700 }}>{o.number} · {formatDate(o.placed_at)}</Typography>
                  <OrderStatusChip status={o.status} label={o.status_label} />
                </Box>
                <Typography variant="body2" color="text.secondary" className="num" sx={{ my: 1 }}>
                  {o.items_count} {o.items_count === 1 ? 'item' : 'itens'} · {formatBRL(o.total_cents)} · {o.shipping_method_name}
                </Typography>
                {o.tracking_code ? <Typography variant="body2">Rastreio: {o.tracking_code}</Typography> : null}
                <Stack direction="row" spacing={2} sx={{ mt: 2, flexWrap: 'wrap' }}>
                  {o.allowed_actions.can_pay || o.allowed_actions.can_retry_payment ? (
                    <Button component={RouterLink} to={`/checkout/pedido/${o.uuid}`} color="secondary" size="medium">Pagar com PIX</Button>
                  ) : null}
                  {o.allowed_actions.can_reorder && o.status !== 'pending_payment' ? (
                    <Button variant="outlined" size="medium" onClick={() => reorder.start(o.uuid)} loading={reorder.isPending && reorder.pendingUuid === o.uuid}>Comprar novamente</Button>
                  ) : null}
                  <Button component={RouterLink} to={`/conta/pedidos/${o.uuid}`} variant="text" size="medium" aria-label={`Ver detalhes do pedido ${o.number}`}>Ver detalhes</Button>
                </Stack>
              </Card>
            ))}
          </Stack>
          {q.data.meta.last_page > 1 ? (
            <Pagination sx={{ mt: 4, display: 'flex', justifyContent: 'center' }} count={q.data.meta.last_page} page={page} onChange={(_, p) => set('pagina', p > 1 ? String(p) : null)} />
          ) : null}
        </>
      )}
      {reorder.dialog}
    </Box>
  );
}
