import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import ReplayOutlined from '@mui/icons-material/ReplayOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router';
import { useSettings } from '@/features/catalog/hooks/queries';
import { NotFoundPage } from '@/features/errors/pages/NotFoundPage';
import { describeError, toApiError } from '@/shared/api/errors';
import type { OrderDetail } from '@/shared/api/types';
import { formatDateTime } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { formatArea, formatQuantity, formatWeight } from '@/shared/formatters/quantity';
import { NBSP, UNIT_SUFFIX } from '@/shared/saleUnit/labels';
import { ConfirmDialog } from '@/shared/ui/ConfirmDialog';
import { ErrorState } from '@/shared/ui/ErrorState';
import { OrderStatusChip, PAYMENT_STATUS_LABEL } from '@/shared/ui/OrderStatusChip';
import { PageHeading } from '@/shared/ui/PageHeading';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { accountKeys, cancelOrder, requestCancellation } from '../api';
import { OrderTimeline } from '../components/OrderTimeline';
import { useOrder } from '../hooks/queries';
import { useReorder } from '../hooks/useReorder';

export default function OrderDetailPage() {
  const { uuid = '' } = useParams();
  const q = useOrder(uuid);
  if (q.isLoading) return <Box aria-busy="true"><Skeleton height={48} /><Skeleton variant="rectangular" height={240} /></Box>;
  if (q.error) {
    const e = toApiError(q.error);
    if (e.status === 404 || e.status === 403) return <NotFoundPage />;
    return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  }
  if (!q.data) return null;
  return <OrderView order={q.data} />;
}

function OrderView({ order }: { order: OrderDetail }) {
  const qc = useQueryClient();
  const notify = useSnackbar();
  const reorder = useReorder();
  const settings = useSettings();
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [requestOpen, setRequestOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);

  const onUpdated = (o: OrderDetail) => {
    qc.setQueryData(accountKeys.order(o.uuid), o);
    void qc.invalidateQueries({ queryKey: ['me', 'orders', 'list'] });
  };
  const cancel = useMutation({
    mutationFn: () => cancelOrder(order.uuid),
    onSuccess: (o) => {
      onUpdated(o);
      setConfirmCancel(false);
      notify('Pedido cancelado', { severity: 'success' });
    },
    onError: (err) => {
      setConfirmCancel(false);
      notify(describeError(err), { severity: 'error' });
    },
  });
  const request = useMutation({
    mutationFn: () => requestCancellation(order.uuid, reason.trim()),
    onSuccess: (o) => {
      onUpdated(o);
      setRequestOpen(false);
      notify('Solicitação de cancelamento enviada', { severity: 'success' });
    },
    onError: (err) => setReasonError(toApiError(err).fieldMessage('reason') ?? describeError(err)),
  });

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      notify('Código copiado', { severity: 'info' });
    } catch {
      /* ignora */
    }
  };
  const whatsapp = settings.data?.store.whatsapp;
  const a = order.allowed_actions;
  const ship = order.shipping;

  return (
    <Box>
      <Link component={RouterLink} to="/conta/pedidos">‹ Pedidos</Link>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 3, flexWrap: 'wrap', mt: 2 }}>
        <PageHeading sx={{ mb: 0 }}>Pedido {order.number}</PageHeading>
        <OrderStatusChip status={order.status} label={order.status_label} />
      </Box>
      <Typography color="text.secondary" sx={{ mb: 3 }}>Feito em {formatDateTime(order.placed_at)}</Typography>
      <Stack direction="row" spacing={2} sx={{ mb: 4, flexWrap: 'wrap', gap: 2 }}>
        {a.can_reorder ? (
          <Button variant="outlined" startIcon={<ReplayOutlined />} onClick={() => reorder.start(order.uuid)} loading={reorder.isPending}>
            {reorder.isPending ? 'Adicionando…' : 'Comprar novamente'}
          </Button>
        ) : null}
        {whatsapp ? (
          <Button variant="text" href={`https://wa.me/55${whatsapp}?text=${encodeURIComponent(`Sobre o pedido ${order.number}`)}`} target="_blank" rel="noopener">
            Falar sobre este pedido (WhatsApp)
          </Button>
        ) : null}
      </Stack>

      {order.status === 'pending_payment' && (a.can_pay || a.can_retry_payment) ? (
        <Alert severity="warning" sx={{ mb: 4 }} action={<Button component={RouterLink} to={`/checkout/pedido/${order.uuid}`} color="secondary" size="medium">Pagar com PIX</Button>}>
          Aguardando pagamento{order.expires_at ? ` até ${formatDateTime(order.expires_at)}` : ''}.
        </Alert>
      ) : null}
      {order.cancellation_request ? (
        <Alert severity="info" sx={{ mb: 4 }}>Cancelamento solicitado em {formatDateTime(order.cancellation_request.requested_at)}. Nossa equipe vai analisar.</Alert>
      ) : null}

      <Card sx={{ p: 4, mb: 4 }}>
        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Acompanhamento</Typography>
        <OrderTimeline order={order} />
        {ship.tracking_code ? (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mt: 2 }}>
            <Typography>Rastreio {ship.tracking_code}</Typography>
            <IconButton aria-label="Copiar código de rastreio" onClick={() => copy(ship.tracking_code!)}><ContentCopyOutlined /></IconButton>
            {ship.tracking_url ? <Link href={ship.tracking_url} target="_blank" rel="noopener">Rastrear ↗</Link> : null}
          </Box>
        ) : null}
        {order.status === 'ready_for_pickup' && ship.pickup_address ? (
          <Alert severity="info" sx={{ mt: 2 }}>
            Pronto para retirada: {ship.pickup_address.street}, {ship.pickup_address.number} – {ship.pickup_address.city}/{ship.pickup_address.state}
            {ship.pickup_address.opening_hours ? ` · ${ship.pickup_address.opening_hours}` : ''}. Leve um documento com foto ou o número do pedido.
          </Alert>
        ) : null}
      </Card>

      <Card sx={{ p: 4, mb: 4 }}>
        <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Itens</Typography>
        {order.items.map((i) => (
          <Box key={i.sku} sx={{ py: 2, borderBottom: 1, borderColor: 'divider' }}>
            <Typography sx={{ fontWeight: 600 }}>
              {i.product_url_path ? <Link component={RouterLink} to={i.product_url_path}>{i.product_name}</Link> : i.product_name} · {i.variant_name} · SKU {i.sku}
            </Typography>
            {i.sale_unit === 'SQUARE_METER' ? <Typography variant="body2" className="num">{i.configuration_label}{i.area_m2 !== null ? ` = ${formatArea(i.area_m2)}` : ''}</Typography> : null}
            <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
              <Typography variant="body2" className="num">
                {i.sale_unit === 'SQUARE_METER' ? `${formatArea(i.billable_quantity)}${i.min_area_applied ? ' (mínimo)' : ''}` : formatQuantity(i.billable_quantity, i.sale_unit)} × {formatBRL(i.unit_price_cents)}
                {NBSP}
                {UNIT_SUFFIX[i.sale_unit]}
              </Typography>
              <Typography className="num" sx={{ fontWeight: 600 }}>{formatBRL(i.total_cents)}</Typography>
            </Box>
          </Box>
        ))}
        <Box component="dl" sx={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', columnGap: 4, rowGap: 1, mt: 3, '& dt': { fontWeight: 600 }, '& dd': { m: 0 } }}>
          <dt>Entrega</dt>
          <dd>{ship.method_name}{ship.delivery_label ? ` · ${ship.delivery_label}` : ''}</dd>
          <dt>Endereço</dt>
          <dd>{ship.address?.formatted ?? (ship.pickup_address ? `Retirada: ${ship.pickup_address.street}, ${ship.pickup_address.number} – ${ship.pickup_address.city}/${ship.pickup_address.state}` : '—')}</dd>
          <dt>Pagamento</dt>
          <dd>PIX · {PAYMENT_STATUS_LABEL[order.payment_status]}{order.paid_at ? ` em ${formatDateTime(order.paid_at)}` : ''}</dd>
          {order.notes ? (<><dt>Observações</dt><dd>{order.notes}</dd></>) : null}
        </Box>
        <Typography className="num" sx={{ mt: 3 }}>
          Subtotal {formatBRL(order.totals.subtotal_cents)} · Frete {formatBRL(order.totals.shipping_cents - order.totals.shipping_discount_cents)} · Desconto {formatBRL(order.totals.discount_cents)}
          {order.coupon_code ? ` (${order.coupon_code})` : ''} · Peso {formatWeight(order.total_weight_grams)}
        </Typography>
        <Typography variant="total" component="p" sx={{ mt: 1 }}>Total {formatBRL(order.totals.total_cents)}</Typography>
      </Card>

      <Stack direction="row" spacing={2}>
        {a.can_cancel ? <Button color="error" variant="outlined" onClick={() => setConfirmCancel(true)}>Cancelar pedido</Button> : null}
        {a.can_request_cancellation ? <Button variant="outlined" onClick={() => setRequestOpen(true)}>Solicitar cancelamento</Button> : null}
      </Stack>

      <ConfirmDialog open={confirmCancel} title={`Cancelar o pedido ${order.number}?`} confirmLabel="Cancelar pedido" onConfirm={() => cancel.mutate()} onClose={() => setConfirmCancel(false)} loading={cancel.isPending}>
        Os itens reservados serão liberados e o PIX deixará de valer.
      </ConfirmDialog>
      <Dialog open={requestOpen} onClose={(_, r) => r !== 'backdropClick' && setRequestOpen(false)} aria-labelledby="req-cancel" fullWidth maxWidth="sm">
        <DialogTitle id="req-cancel">Solicitar cancelamento do pedido {order.number}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" sx={{ mb: 2 }}>O pedido já foi pago; nossa equipe analisa a solicitação e faz o estorno quando aprovado.</Typography>
          <TextField label="Motivo" multiline minRows={3} value={reason} onChange={(e) => setReason(e.target.value.slice(0, 500))} error={Boolean(reasonError)} helperText={reasonError ?? `${reason.length}/500`} required />
        </DialogContent>
        <DialogActions>
          <Button variant="outlined" onClick={() => setRequestOpen(false)} autoFocus>Voltar</Button>
          <Button
            onClick={() => {
              if (reason.trim().length < 5) return setReasonError('Descreva o motivo (mínimo 5 caracteres).');
              setReasonError(null);
              request.mutate();
            }}
            loading={request.isPending}
          >
            Enviar solicitação
          </Button>
        </DialogActions>
      </Dialog>
      {reorder.dialog}
    </Box>
  );
}
