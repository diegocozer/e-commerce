import type {
  ActorType,
  CouponType,
  CustomerType,
  InventoryMovementType,
  OrderPaymentStatus,
  OrderStatus,
  PaymentRecordStatus,
  PriceListKind,
  PromotionDiscountType,
  ShippingMethodType,
  ShippingPriceType,
  WeightBasis,
} from '@/shared/api/types';

export type ChipColor = 'default' | 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';

/** UX §6.1.4 */
export const ORDER_STATUS: Record<OrderStatus, { label: string; color: ChipColor; variant: 'filled' | 'outlined' }> = {
  pending_payment: { label: 'Aguardando pagamento', color: 'warning', variant: 'filled' },
  paid: { label: 'Pago', color: 'success', variant: 'filled' },
  processing: { label: 'Em separação', color: 'info', variant: 'filled' },
  shipped: { label: 'Enviado', color: 'info', variant: 'filled' },
  ready_for_pickup: { label: 'Pronto para retirada', color: 'primary', variant: 'filled' },
  delivered: { label: 'Entregue', color: 'success', variant: 'outlined' },
  picked_up: { label: 'Retirado', color: 'success', variant: 'outlined' },
  cancelled: { label: 'Cancelado', color: 'error', variant: 'outlined' },
};

export const ORDER_STATUSES = Object.keys(ORDER_STATUS) as OrderStatus[];

export const PAYMENT_STATUS: Record<OrderPaymentStatus, { label: string; color: ChipColor }> = {
  pending: { label: 'Pendente', color: 'warning' },
  approved: { label: 'Aprovado', color: 'success' },
  failed: { label: 'Falhou', color: 'error' },
  refunded: { label: 'Estornado', color: 'default' },
  expired: { label: 'Expirado', color: 'default' },
};

export const PAYMENT_RECORD_STATUS: Record<PaymentRecordStatus, string> = {
  pending: 'Pendente',
  approved: 'Aprovado',
  failed: 'Falhou',
  expired: 'Expirado',
  cancelled: 'Cancelado',
  refunded: 'Estornado',
  partially_refunded: 'Estornado parcialmente',
};

export const MOVEMENT_TYPE: Record<InventoryMovementType, { label: string; color: ChipColor }> = {
  in: { label: 'Entrada', color: 'success' },
  out: { label: 'Saída', color: 'info' },
  reserve: { label: 'Reserva', color: 'warning' },
  release: { label: 'Liberação', color: 'default' },
  return: { label: 'Devolução', color: 'primary' },
  adjust: { label: 'Ajuste', color: 'secondary' },
};

export const SHIPPING_METHOD_TYPE: Record<ShippingMethodType, string> = {
  pickup: 'Retirada na loja',
  own_delivery: 'Entrega própria',
  table_rate: 'Frete por tabela',
  carrier: 'Transportadora',
};

export const SHIPPING_PRICE_TYPE: Record<ShippingPriceType, string> = {
  fixed: 'Valor fixo',
  per_kg: 'Por kg',
  fixed_plus_per_kg: 'Fixo + por kg',
  percentage_of_subtotal: '% do subtotal',
  free: 'Grátis',
};

export const WEIGHT_BASIS: Record<WeightBasis, string> = {
  real: 'Peso real',
  chargeable: 'Maior entre real e cubado',
};

export const CUSTOMER_TYPE: Record<CustomerType, string> = { individual: 'PF', company: 'PJ' };

export const ACTOR_TYPE: Record<ActorType, string> = { admin: 'Admin', customer: 'Cliente', system: 'Sistema' };

export const COUPON_TYPE: Record<CouponType, string> = {
  percent: 'Percentual',
  fixed: 'Valor fixo',
  free_shipping: 'Frete grátis',
};

export const PROMOTION_TYPE: Record<PromotionDiscountType, string> = { percent: 'Percentual', fixed: 'Valor fixo' };

export const PRICE_LIST_KIND: Record<PriceListKind, string> = {
  retail: 'Varejo',
  wholesale: 'Atacado',
  reseller: 'Revenda',
  custom: 'Específica',
};

export const PROMOTION_STATUS: Record<'scheduled' | 'active' | 'ended' | 'inactive', { label: string; color: ChipColor }> = {
  scheduled: { label: 'Agendada', color: 'info' },
  active: { label: 'Ativa', color: 'success' },
  ended: { label: 'Encerrada', color: 'default' },
  inactive: { label: 'Inativa', color: 'default' },
};

export const COUPON_STATUS: Record<'scheduled' | 'active' | 'expired' | 'exhausted' | 'inactive', { label: string; color: ChipColor }> = {
  scheduled: { label: 'Agendado', color: 'info' },
  active: { label: 'Ativo', color: 'success' },
  expired: { label: 'Expirado', color: 'default' },
  exhausted: { label: 'Esgotado', color: 'warning' },
  inactive: { label: 'Inativo', color: 'default' },
};

export const CANCEL_REASON: Record<string, string> = {
  payment_expired: 'Pagamento não realizado no prazo',
  customer: 'Cancelado pelo cliente',
  admin: 'Cancelado pela loja',
  payment_failed: 'Pagamento recusado',
};
