import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import type { OrderDetail, OrderStatus } from '@/shared/api/types';
import { formatDate, formatDateTime } from '@/shared/formatters/date';
import { ORDER_STATUS_LABEL } from '@/shared/ui/OrderStatusChip';

const DELIVERY_FLOW: OrderStatus[] = ['pending_payment', 'paid', 'processing', 'shipped', 'delivered'];
const PICKUP_FLOW: OrderStatus[] = ['pending_payment', 'paid', 'processing', 'ready_for_pickup', 'picked_up'];

/** Timeline (UX §4.9.3): <ol>, concluídos ●, atual destacado, futuros ○; cancelado em vermelho. */
export function OrderTimeline({ order }: { order: OrderDetail }) {
  const flow = order.shipping.method_type === 'pickup' ? PICKUP_FLOW : DELIVERY_FLOW;
  const reached = new Set(order.timeline.map((t) => t.status));
  const future = order.status === 'cancelled' ? [] : flow.filter((s) => !reached.has(s));
  return (
    <Box component="ol" sx={{ listStyle: 'none', p: 0, m: 0 }} aria-label="Acompanhamento do pedido">
      {order.timeline.map((t, i) => {
        const last = i === order.timeline.length - 1;
        const cancelled = t.status === 'cancelled';
        return (
          <Box component="li" key={`${t.status}-${t.occurred_at}`} sx={{ display: 'flex', gap: 3, py: 1.5 }} aria-current={last ? 'step' : undefined}>
            <Box aria-hidden sx={{ width: 14, height: 14, mt: 1, borderRadius: '50%', flexShrink: 0, bgcolor: cancelled ? 'error.main' : 'primary.main', outline: last ? '3px solid' : 'none', outlineColor: 'primary.light' }} />
            <Box>
              <Typography sx={{ fontWeight: last ? 700 : 500 }}>{t.status_label}</Typography>
              <Typography variant="body2" color="text.secondary" className="num">{formatDateTime(t.occurred_at)}</Typography>
              {t.note ? <Typography variant="body2">{t.note}</Typography> : null}
              {cancelled && order.cancel_reason_label ? <Typography variant="body2" color="error.main">Cancelado: {order.cancel_reason_label}</Typography> : null}
            </Box>
          </Box>
        );
      })}
      {future.map((s) => (
        <Box component="li" key={s} sx={{ display: 'flex', gap: 3, py: 1.5, color: 'text.secondary' }}>
          <Box aria-hidden sx={{ width: 14, height: 14, mt: 1, borderRadius: '50%', border: 2, borderColor: 'grey.400', flexShrink: 0 }} />
          <Box>
            <Typography>{ORDER_STATUS_LABEL[s]}</Typography>
            {(s === 'delivered') && order.shipping.estimated_delivery_date ? (
              <Typography variant="body2">previsão até {formatDate(order.shipping.estimated_delivery_date)}</Typography>
            ) : null}
          </Box>
        </Box>
      ))}
    </Box>
  );
}
