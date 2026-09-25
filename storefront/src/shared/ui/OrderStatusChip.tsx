import Chip from '@mui/material/Chip';
import type { OrderPaymentStatus, OrderStatus } from '../api/types';

// UX §6.1.4
export const ORDER_STATUS_LABEL: Record<OrderStatus, string> = {
  pending_payment: 'Aguardando pagamento',
  paid: 'Pago',
  processing: 'Em separação',
  shipped: 'Enviado',
  ready_for_pickup: 'Pronto para retirada',
  delivered: 'Entregue',
  picked_up: 'Retirado',
  cancelled: 'Cancelado',
};

export const PAYMENT_STATUS_LABEL: Record<OrderPaymentStatus, string> = {
  pending: 'Pendente',
  approved: 'Aprovado',
  failed: 'Falhou',
  refunded: 'Estornado',
  expired: 'Expirado',
};

const STYLE: Record<OrderStatus, { color: 'warning' | 'success' | 'info' | 'primary' | 'error'; variant: 'filled' | 'outlined'; icon?: string }> = {
  pending_payment: { color: 'warning', variant: 'filled', icon: '⏳' },
  paid: { color: 'success', variant: 'filled' },
  processing: { color: 'info', variant: 'filled' },
  shipped: { color: 'info', variant: 'filled', icon: '🚚' },
  ready_for_pickup: { color: 'primary', variant: 'filled', icon: '🏬' },
  delivered: { color: 'success', variant: 'outlined' },
  picked_up: { color: 'success', variant: 'outlined' },
  cancelled: { color: 'error', variant: 'outlined' },
};

export function OrderStatusChip({ status, label }: { status: OrderStatus; label?: string }) {
  const s = STYLE[status];
  const text = label ?? ORDER_STATUS_LABEL[status];
  return <Chip size="small" color={s.color} variant={s.variant} label={s.icon ? `${s.icon} ${text}` : text} />;
}
