import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import type { CartItemIssue, CheckoutSummary, CouponIssue, SaleUnit, StockIssue } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatQuantity } from '@/shared/formatters/quantity';
import { NBSP, UNIT_SUFFIX } from '@/shared/saleUnit/labels';

export type Conflict =
  | { kind: 'price_changed'; previous: CheckoutSummary | null; summary: CheckoutSummary }
  | { kind: 'insufficient_stock'; items: StockIssue[]; units: Record<number, SaleUnit> }
  | { kind: 'coupon_invalid'; coupon: CouponIssue | null; summary: CheckoutSummary | null }
  | { kind: 'cart_invalid'; items: CartItemIssue[]; message: string }
  | { kind: 'too_many_pending_orders'; orders: { uuid: string; number: string }[]; message: string }
  | { kind: 'idempotency_conflict'; order: { uuid: string; number: string } | null; message: string };

/** 409/422 do checkout (UX §4.6.6): Dialog não fechável por clique fora, com o que mudou. */
export function ConflictDialog({ conflict, onReview, onBackToCart, onRemoveCoupon, onClose }: {
  conflict: Conflict | null;
  onReview: () => void;
  onBackToCart: () => void;
  onRemoveCoupon: () => void;
  onClose: () => void;
}) {
  if (!conflict) return null;
  let body: React.ReactNode = null;
  let actions: React.ReactNode = null;
  switch (conflict.kind) {
    case 'price_changed': {
      const prevItems = new Map((conflict.previous?.items ?? []).map((i) => [i.variant_id, i]));
      const changed = conflict.summary.items.filter((i) => {
        const prev = prevItems.get(i.variant_id);
        return (prev && prev.unit_price_cents !== i.unit_price_cents) || i.warnings?.some((w) => w.code === 'price_changed');
      });
      const oldShip = conflict.previous?.totals.shipping_cents ?? null;
      const newShip = conflict.summary.totals.shipping_cents;
      body = (
        <ul style={{ paddingLeft: 16 }}>
          {changed.map((i) => {
            const w = i.warnings?.find((x) => x.code === 'price_changed');
            const before = w && w.code === 'price_changed' ? w.previous_unit_price_cents : (prevItems.get(i.variant_id)?.unit_price_cents ?? null);
            const unit = i.sale_unit ?? 'UNIT';
            return (
              <li key={i.variant_id}>
                {i.product?.name ?? 'Item'}: {before !== null ? formatBRL(before) : '—'} → {i.unit_price_cents !== null ? formatBRL(i.unit_price_cents) : '—'}
                {NBSP}
                {UNIT_SUFFIX[unit]}
              </li>
            );
          })}
          {oldShip !== null && newShip !== null && oldShip !== newShip ? (
            <li>
              Frete: {formatBRL(oldShip)} → {formatBRL(newShip)}
            </li>
          ) : null}
          <li>
            Novo total: {conflict.previous ? <s>{formatBRL(conflict.previous.totals.total_cents)}</s> : null} → <strong>{formatBRL(conflict.summary.totals.total_cents)}</strong>
          </li>
        </ul>
      );
      actions = <Button onClick={onReview}>Revisar e confirmar</Button>;
      break;
    }
    case 'insufficient_stock':
      body = (
        <ul style={{ paddingLeft: 16 }}>
          {conflict.items.map((i) => {
            const unit = conflict.units[i.variant_id] ?? 'UNIT';
            return (
              <li key={i.variant_id}>
                {i.product_name}: disponível {formatQuantity(i.available_quantity, unit)} (você pediu {formatQuantity(i.requested_quantity, unit)})
              </li>
            );
          })}
        </ul>
      );
      actions = <Button onClick={onBackToCart}>Voltar ao carrinho</Button>;
      break;
    case 'coupon_invalid':
      body = (
        <Typography>
          Cupom {conflict.coupon?.code}: {conflict.coupon?.message ?? 'deixou de valer.'}
          {conflict.summary ? ` Novo total sem o cupom: ${formatBRL(conflict.summary.totals.total_cents)}.` : ''}
        </Typography>
      );
      actions = <Button onClick={onRemoveCoupon}>Remover cupom e revisar</Button>;
      break;
    case 'cart_invalid':
      body = (
        <>
          <Typography>{conflict.message}</Typography>
          <ul style={{ paddingLeft: 16 }}>
            {conflict.items.map((i) => (
              <li key={`${i.variant_id}-${i.cart_item_id}`}>
                {i.product_name}: {i.message}
              </li>
            ))}
          </ul>
        </>
      );
      actions = <Button onClick={onBackToCart}>Voltar ao carrinho</Button>;
      break;
    case 'too_many_pending_orders':
      body = (
        <>
          <Typography>{conflict.message}</Typography>
          <ul style={{ paddingLeft: 16 }}>
            {conflict.orders.map((o) => (
              <li key={o.uuid}>
                <Link component={RouterLink} to={`/conta/pedidos/${o.uuid}`}>
                  {o.number}
                </Link>
              </li>
            ))}
          </ul>
        </>
      );
      actions = <Button onClick={onClose}>Entendi</Button>;
      break;
    case 'idempotency_conflict':
      body = (
        <Typography>
          {conflict.message}{' '}
          {conflict.order ? (
            <Link component={RouterLink} to={`/checkout/pedido/${conflict.order.uuid}`}>
              Ver pedido {conflict.order.number}
            </Link>
          ) : null}
        </Typography>
      );
      actions = <Button onClick={onReview}>Revisar e confirmar</Button>;
      break;
  }
  return (
    <Dialog open onClose={(_, reason) => reason !== 'backdropClick' && onClose()} aria-labelledby="conflict-title" maxWidth="sm" fullWidth>
      <DialogTitle id="conflict-title">Algumas informações mudaram</DialogTitle>
      <DialogContent>{body}</DialogContent>
      <DialogActions sx={{ px: 6, pb: 4 }}>{actions}</DialogActions>
    </Dialog>
  );
}
