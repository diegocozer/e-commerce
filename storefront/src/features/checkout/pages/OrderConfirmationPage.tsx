import CheckCircle from '@mui/icons-material/CheckCircle';
import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import HourglassEmptyOutlined from '@mui/icons-material/HourglassEmptyOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Grid from '@mui/material/Grid';
import LinearProgress from '@mui/material/LinearProgress';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router';
import { accountKeys, devApprovePayment, fetchOrderStatus, retryPayment, useOrder, useReorder } from '@/features/account';
import { useSettings } from '@/features/catalog/hooks/queries';
import { NotFoundPage } from '@/features/errors/pages/NotFoundPage';
import { describeError, toApiError } from '@/shared/api/errors';
import type { OrderDetail } from '@/shared/api/types';
import { formatDate, formatCountdown } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { env } from '@/shared/lib/env';
import { serverNow } from '@/shared/lib/serverClock';
import { ErrorState } from '@/shared/ui/ErrorState';
import { Seo } from '@/shared/ui/Seo';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { SummaryPanel } from '@/shared/ui/SummaryPanel';

const POLL_MS = 5000;
const POLL_MAX_MS = 35 * 60_000;

function useCountdown(expiresAt: string | null | undefined) {
  const [now, setNow] = useState(() => serverNow());
  useEffect(() => {
    if (!expiresAt) return;
    const id = setInterval(() => setNow(serverNow()), 1000);
    return () => clearInterval(id);
  }, [expiresAt]);
  return expiresAt ? Date.parse(expiresAt) - now : null;
}

/** Confirmação + PIX (UX §4.7): QR, copia-e-cola, contagem regressiva, polling 5 s. */
export default function OrderConfirmationPage() {
  const { uuid = '' } = useParams();
  const order = useOrder(uuid);
  if (order.isLoading) {
    return (
      <Box aria-busy="true" sx={{ maxWidth: 640 }}>
        <Seo title="Pedido" robots="noindex,nofollow" />
        <Skeleton variant="rectangular" width={240} height={240} sx={{ mb: 3 }} />
        <Skeleton height={32} />
        <Skeleton height={32} />
        <Skeleton height={32} />
      </Box>
    );
  }
  if (order.error) {
    const e = toApiError(order.error);
    if (e.status === 404) return <NotFoundPage />;
    return <ErrorState error={order.error} onRetry={() => void order.refetch()} />;
  }
  if (!order.data) return null;
  return <PixView order={order.data} />;
}

function PixView({ order }: { order: OrderDetail }) {
  const qc = useQueryClient();
  const notify = useSnackbar();
  const settings = useSettings();
  const reorder = useReorder();
  const mountedAt = useRef(Date.now());
  const [copied, setCopied] = useState(false);
  const [generating, setGenerating] = useState(false);
  const titleRef = useRef<HTMLHeadingElement>(null);

  const pending = order.status === 'pending_payment' && order.payment_status === 'pending';
  const status = useQuery({
    queryKey: accountKeys.orderStatus(order.uuid),
    queryFn: () => fetchOrderStatus(order.uuid),
    enabled: pending,
    refetchInterval: (q) => {
      const d = q.state.data;
      if (d && (d.status !== 'pending_payment' || d.payment_status !== 'pending')) return false;
      if (Date.now() - mountedAt.current > POLL_MAX_MS) return false;
      return POLL_MS;
    },
    refetchIntervalInBackground: false,
    retry: 3,
    retryDelay: (n) => Math.min(30_000, 2000 * 2 ** n),
  });

  // Mudou para pago/cancelado → recarrega o detalhe.
  const polledStatus = status.data?.status;
  const polledPayment = status.data?.payment_status;
  useEffect(() => {
    if (polledStatus && (polledStatus !== order.status || polledPayment !== order.payment_status)) {
      void qc.invalidateQueries({ queryKey: accountKeys.order(order.uuid) });
    }
  }, [polledStatus, polledPayment, order.status, order.payment_status, order.uuid, qc]);

  const paid = order.payment_status === 'approved' || ['paid', 'processing', 'shipped', 'delivered', 'ready_for_pickup', 'picked_up'].includes(order.status);
  useEffect(() => {
    if (paid) titleRef.current?.focus();
  }, [paid]);

  const pix = order.payment?.pix ?? null;
  const remaining = useCountdown(pix?.expires_at ?? order.expires_at);
  const expired = order.status === 'cancelled' || order.payment_status === 'expired' || (remaining !== null && remaining <= 0 && !paid);
  const failed = order.payment_status === 'failed';
  const total = order.totals.total_cents;
  const whatsapp = settings.data?.store.whatsapp;

  const copy = async () => {
    if (!pix) return;
    try {
      await navigator.clipboard.writeText(pix.copy_paste);
    } catch {
      const el = document.getElementById('pix-copy-paste') as HTMLInputElement | null;
      el?.select();
    }
    setCopied(true);
    notify('Código PIX copiado', { severity: 'success' });
    setTimeout(() => setCopied(false), 3000);
  };

  const generate = async () => {
    setGenerating(true);
    try {
      await retryPayment(order.uuid);
      await qc.invalidateQueries({ queryKey: accountKeys.order(order.uuid) });
    } catch (err) {
      notify(describeError(err), { severity: 'error' });
    } finally {
      setGenerating(false);
    }
  };

  const summary = (
    <Card sx={{ p: 4 }}>
      <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Resumo do pedido</Typography>
      {order.items.map((i) => (
        <Box key={i.sku} sx={{ display: 'flex', justifyContent: 'space-between', py: 1 }}>
          <Typography variant="body2">
            {i.product_name} · {i.configuration_label}
          </Typography>
          <Typography variant="body2" className="num">{formatBRL(i.total_cents)}</Typography>
        </Box>
      ))}
      <SummaryPanel
        subtotalCents={order.totals.subtotal_cents}
        discountCents={order.totals.discount_cents}
        couponCode={order.coupon_code}
        shippingCents={order.totals.shipping_cents}
        shippingDiscountCents={order.totals.shipping_discount_cents}
        totalCents={total}
        weightGrams={order.total_weight_grams}
      />
      <Typography variant="body2" sx={{ mt: 2 }}>
        {order.shipping.method_name}
        {order.shipping.address ? ` — ${order.shipping.address.formatted}` : ''}
      </Typography>
    </Card>
  );

  if (paid) {
    return (
      <Box sx={{ textAlign: 'center', py: 6, maxWidth: 640, mx: 'auto' }}>
        <Seo title={`Pedido ${order.number} pago`} robots="noindex,nofollow" />
        <CheckCircle sx={{ fontSize: 80, color: 'success.main', '@media (prefers-reduced-motion: no-preference)': { animation: 'pop 300ms ease-out' }, '@keyframes pop': { from: { transform: 'scale(0.6)' }, to: { transform: 'scale(1)' } } }} aria-hidden />
        <Typography variant="h1" component="h1" tabIndex={-1} ref={titleRef} sx={{ outline: 'none' }} aria-live="assertive">
          Pagamento confirmado!
        </Typography>
        <Typography sx={{ my: 3 }}>
          Pedido {order.number} pago. Você receberá atualizações por e-mail.{' '}
          {order.shipping.method_type === 'pickup'
            ? 'Avisaremos quando estiver pronto para retirada.'
            : order.shipping.estimated_delivery_date
              ? `Previsão de entrega: até ${formatDate(order.shipping.estimated_delivery_date)}.`
              : ''}
        </Typography>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ justifyContent: 'center', mb: 6 }}>
          <Button component={RouterLink} to={`/conta/pedidos/${order.uuid}`}>Acompanhar pedido</Button>
          <Button component={RouterLink} to="/" variant="outlined">Continuar comprando</Button>
        </Stack>
        {summary}
      </Box>
    );
  }

  return (
    <Grid container spacing={6}>
      <Seo title={`Pedido ${order.number}`} robots="noindex,nofollow" />
      <Grid size={{ xs: 12, md: 7 }}>
        <Typography variant="h1" component="h1" tabIndex={-1} sx={{ outline: 'none', mb: 2 }}>
          {expired ? `Pedido ${order.number}` : `✓ Pedido ${order.number} criado!`}
        </Typography>
        {expired ? (
          <Alert severity="error" sx={{ mb: 4 }} action={<Button color="inherit" onClick={() => reorder.start(order.uuid)} loading={reorder.isPending}>Comprar novamente</Button>}>
            O prazo para pagamento expirou e o pedido foi cancelado.
          </Alert>
        ) : failed ? (
          <Alert severity="error" sx={{ mb: 4 }}>
            O pagamento não foi concluído.{' '}
            {whatsapp ? <Link href={`https://wa.me/55${whatsapp}?text=${encodeURIComponent(`Pedido ${order.number}`)}`} target="_blank" rel="noopener">Falar no WhatsApp</Link> : null}
          </Alert>
        ) : (
          <>
            <Typography sx={{ mb: 2 }}>Pague com PIX para confirmar.</Typography>
            {remaining !== null ? (
              <Box sx={{ mb: 3 }}>
                <Typography className="num" sx={{ fontWeight: 700, color: remaining < 5 * 60_000 ? 'warning.main' : 'text.primary' }}>
                  Expira em {formatCountdown(remaining)}
                </Typography>
                {remaining < 5 * 60_000 ? <Typography role="status" variant="body2" color="warning.main">Menos de 5 minutos para pagar</Typography> : null}
                <LinearProgress
                  variant="determinate"
                  value={Math.max(0, Math.min(100, (remaining / ((settings.data?.checkout.pix_expiry_minutes ?? 30) * 60_000)) * 100))}
                  color={remaining < 5 * 60_000 ? 'warning' : 'primary'}
                  aria-label="Tempo restante para pagar"
                  sx={{ mt: 1 }}
                />
              </Box>
            ) : null}
            <Typography variant="total" component="p" sx={{ mb: 3 }}>
              Valor: {formatBRL(total)}
            </Typography>
            {pix ? (
              <>
                {pix.qr_code_base64 ? (
                  <Box component="img" src={`data:image/png;base64,${pix.qr_code_base64}`} alt={`QR Code PIX do pedido ${order.number}, valor ${formatBRL(total)}`} sx={{ width: 240, height: 240, display: 'block', mb: 3, border: 1, borderColor: 'divider', borderRadius: 2 }} />
                ) : null}
                <Typography variant="body2" sx={{ mb: 1, fontWeight: 600 }}>
                  PIX copia e cola
                </Typography>
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField id="pix-copy-paste" value={pix.copy_paste} size="small" slotProps={{ htmlInput: { readOnly: true, 'aria-label': 'Código PIX copia e cola' } }} onFocus={(e) => e.target.select()} />
                  <Button color="secondary" startIcon={copied ? <CheckCircle /> : <ContentCopyOutlined />} onClick={copy} sx={{ whiteSpace: 'nowrap' }}>
                    {copied ? 'Código copiado' : 'Copiar código PIX'}
                  </Button>
                </Stack>
                <span className="visually-hidden" aria-live="polite">{copied ? 'Código PIX copiado' : ''}</span>
                <ol style={{ paddingLeft: 20 }}>
                  <li>Abra o app do seu banco</li>
                  <li>Escolha PIX › Ler QR Code ou Copia e cola</li>
                  <li>Confirme o pagamento</li>
                </ol>
                <Typography sx={{ display: 'flex', alignItems: 'center', gap: 1 }} aria-live="polite">
                  <HourglassEmptyOutlined fontSize="small" aria-hidden /> Aguardando pagamento… Atualizamos automaticamente.
                </Typography>
                {status.error ? (
                  <Typography variant="caption" color="text.secondary">
                    Sem conexão — tentando de novo
                  </Typography>
                ) : null}
              </>
            ) : (
              <Alert severity="warning" action={order.allowed_actions.can_retry_payment || order.allowed_actions.can_pay ? <Button color="inherit" onClick={generate} loading={generating}>Gerar PIX</Button> : undefined}>
                Seu pedido foi criado, mas o PIX ainda não foi gerado.
              </Alert>
            )}
            {env.isDev ? (
              <Button variant="text" size="small" sx={{ mt: 3 }} onClick={() => void devApprovePayment(order.uuid).then(() => status.refetch())}>
                Simular pagamento (dev)
              </Button>
            ) : null}
          </>
        )}
        <Box sx={{ mt: 4 }}>
          <Button component={RouterLink} to="/conta/pedidos" variant="outlined">Ver meus pedidos</Button>
        </Box>
        {reorder.dialog}
      </Grid>
      <Grid size={{ xs: 12, md: 5 }}>{summary}</Grid>
    </Grid>
  );
}
