import MoreVertIcon from '@mui/icons-material/MoreVertOutlined';
import { Alert, Box, Button, Grid, IconButton, Menu, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminTransition } from '@/shared/api/types';
import { Can } from '@/shared/auth';
import { formatDateTime } from '@/shared/formatters/date';
import { CANCEL_REASON } from '@/shared/formatters/labels';
import { ConfirmDialog, ErrorState, LoadingBlock, notify, OrderStatusChip, PageHeader, PaymentStatusChip } from '@/shared/ui';
import { useDismissCancellation, useOrder } from '../api';
import { CancelOrderDialog } from '../components/CancelOrderDialog';
import { OrderItemsCard } from '../components/OrderItemsCard';
import { CustomerCard, HistoryCard, NotesCard, PaymentCard, ShippingCard } from '../components/OrderSideCards';
import { TransitionDialog } from '../components/TransitionDialog';

export default function OrderDetailPage() {
  const id = Number(useParams().id);
  const navigate = useNavigate();
  const q = useOrder(id);
  const order = q.data;
  useBreadcrumb(order?.number);
  const [transition, setTransition] = useState<AdminTransition | null>(null);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [dismissOpen, setDismissOpen] = useState(false);
  const [dismissNote, setDismissNote] = useState('');
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const dismiss = useDismissCancellation(id);

  if (q.isPending) return <LoadingBlock lines={8} label="Carregando pedido" />;
  if (q.error || !order) {
    if (isApiError(q.error) && q.error.status === 404) return <ErrorState title="Pedido não encontrado." error={q.error} />;
    return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  }

  return (
    <>
      <PageHeader
        title={`Pedido ${order.number}`}
        back={{ to: '/pedidos', label: 'Pedidos' }}
        chips={
          <>
            <OrderStatusChip status={order.status} size="medium" />
            <PaymentStatusChip status={order.payment_status} size="medium" />
          </>
        }
        subtitle={`Criado ${formatDateTime(order.placed_at)}${order.paid_at ? ` · Pago ${formatDateTime(order.paid_at)}` : ''}`}
        actions={
          <>
            {order.allowed_transitions.map((t) => (
              <Button key={t.to_status} variant="contained" onClick={() => setTransition(t)}>
                {t.label}
              </Button>
            ))}
            {order.can_cancel && (
              <Button color="error" variant="outlined" onClick={() => setCancelOpen(true)}>
                Cancelar pedido
              </Button>
            )}
            <IconButton aria-label="Mais ações" onClick={(e) => setMenu(e.currentTarget)}>
              <MoreVertIcon />
            </IconButton>
            <Menu anchorEl={menu} open={!!menu} onClose={() => setMenu(null)}>
              <MenuItem onClick={() => { setMenu(null); window.print(); }}>Imprimir</MenuItem>
              <MenuItem
                onClick={() => {
                  setMenu(null);
                  void navigator.clipboard?.writeText(window.location.href).then(() => notify.success('Link copiado'));
                }}
              >
                Copiar link
              </MenuItem>
              <Can perm="audit_logs.view">
                <MenuItem onClick={() => navigate(`/auditoria?auditable_type=order&auditable_id=${order.id}`)}>Ver no log de auditoria</MenuItem>
              </Can>
            </Menu>
          </>
        }
      />
      <Stack spacing={3} sx={{ mb: 4 }}>
        {order.status === 'cancelled' && (
          <Alert severity="error">
            Cancelado em {formatDateTime(order.cancelled_at)}
            {order.cancel_reason_code ? ` · ${CANCEL_REASON[order.cancel_reason_code] ?? order.cancel_reason_code}` : ''}
            {order.cancel_reason ? ` — ${order.cancel_reason}` : ''}
          </Alert>
        )}
        {order.cancellation_request && (
          <Alert
            severity="warning"
            action={
              <Can perm="orders.cancel_paid">
                <Button color="inherit" size="small" onClick={() => setDismissOpen(true)}>
                  Recusar solicitação
                </Button>
              </Can>
            }
          >
            O cliente solicitou o cancelamento em {formatDateTime(order.cancellation_request.requested_at)}
            {order.cancellation_request.reason ? `: "${order.cancellation_request.reason}"` : '.'}
          </Alert>
        )}
        {order.status === 'pending_payment' && order.expires_at && <Alert severity="info">Aguardando PIX até {formatDateTime(order.expires_at)}.</Alert>}
      </Stack>
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Stack spacing={4}>
            <OrderItemsCard order={order} />
            <PaymentCard order={order} />
            <HistoryCard order={order} />
          </Stack>
        </Grid>
        <Grid size={{ xs: 12, lg: 5 }}>
          <Stack spacing={4}>
            <CustomerCard order={order} />
            <ShippingCard order={order} />
            <NotesCard key={order.updated_at} order={order} />
          </Stack>
        </Grid>
      </Grid>
      {transition && <TransitionDialog order={order} transition={transition} onClose={() => setTransition(null)} />}
      {cancelOpen && <CancelOrderDialog order={order} onClose={() => setCancelOpen(false)} />}
      <ConfirmDialog
        open={dismissOpen}
        title="Recusar a solicitação de cancelamento?"
        description="O pedido continua ativo. Informe o motivo (registrado na auditoria)."
        confirmLabel="Recusar solicitação"
        loading={dismiss.isPending}
        onClose={() => setDismissOpen(false)}
        onConfirm={() => {
          if (dismissNote.trim().length < 3) return notify.error('Informe um motivo com pelo menos 3 caracteres.');
          dismiss.mutate(dismissNote.trim(), {
            onSuccess: () => {
              notify.success('Solicitação recusada');
              setDismissOpen(false);
            },
            onError: (e) => notify.error(errorMessage(e)),
          });
        }}
      >
        <Box sx={{ mt: 2 }}>
          <TextField label="Motivo" required multiline minRows={2} value={dismissNote} onChange={(e) => setDismissNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} />
        </Box>
      </ConfirmDialog>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 4 }}>
        Atualizado {formatDateTime(order.updated_at)}
      </Typography>
    </>
  );
}
