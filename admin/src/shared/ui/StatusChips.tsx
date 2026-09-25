import { Chip, type ChipProps } from '@mui/material';
import type { OrderPaymentStatus, OrderStatus } from '@/shared/api/types';
import { ORDER_STATUS, PAYMENT_STATUS, type ChipColor } from '@/shared/formatters/labels';

export function OrderStatusChip({ status, size = 'small' }: { status: OrderStatus; size?: ChipProps['size'] }) {
  const s = ORDER_STATUS[status];
  return <Chip size={size} label={s.label} color={s.color} variant={s.variant} />;
}

export function PaymentStatusChip({ status, size = 'small' }: { status: OrderPaymentStatus; size?: ChipProps['size'] }) {
  const s = PAYMENT_STATUS[status];
  return <Chip size={size} label={s.label} color={s.color} variant="outlined" />;
}

export function ActiveChip({ active, on = 'Ativo', off = 'Inativo' }: { active: boolean; on?: string; off?: string }) {
  return <Chip size="small" label={active ? on : off} color={active ? 'success' : 'default'} variant={active ? 'filled' : 'outlined'} />;
}

export function LabelChip({ label, color = 'default' }: { label: string; color?: ChipColor }) {
  return <Chip size="small" label={label} color={color} variant="outlined" />;
}
