import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router';
import { removeCoupon, storeCart } from '@/features/cart';
import { describeError, isShippingCode, toApiError, type ApiError } from '@/shared/api/errors';
import type { CartItemIssue, CheckoutSummary, CouponIssue, SaleUnit, ShippingQuote, StockIssue } from '@/shared/api/types';
import { formatDocument } from '@/shared/formatters/document';
import { formatBRL } from '@/shared/formatters/money';
import { formatArea, formatQuantity } from '@/shared/formatters/quantity';
import { NBSP, UNIT_SUFFIX } from '@/shared/saleUnit/labels';
import { ErrorState } from '@/shared/ui/ErrorState';
import { SummaryPanel } from '@/shared/ui/SummaryPanel';
import { checkoutKeys, fetchCheckoutPreview, type PreviewParams } from '../api';
import { resetIdempotencyKey } from '../hooks/useCheckoutState';
import { usePlaceOrder } from '../hooks/usePlaceOrder';
import { ConflictDialog, type Conflict } from './ConflictDialog';
import { StepHeading } from './StepHeading';

interface Props {
  params: PreviewParams;
  notes: string;
  onNotesChange: (notes: string) => void;
  onEdit: (step: 'endereco' | 'frete' | 'pagamento' | 'identificacao', notice?: string) => void;
  onShippingConflict: (quote: ShippingQuote | null, message: string) => void;
  onPlaced: (orderUuid: string) => void;
  /** Para testes: atrasos de retry (padrão 2 s / 4 s). */
  retryDelaysMs?: number[];
}

function lineMath(i: CheckoutSummary['items'][number]): string {
  if (i.billable_quantity === null || i.unit_price_cents === null) return i.configuration_label;
  const qty = i.sale_unit === 'SQUARE_METER' ? `${formatArea(i.billable_quantity)}${i.min_area_applied ? ' (mínimo)' : ''}` : formatQuantity(i.billable_quantity, i.sale_unit);
  return `${qty} × ${formatBRL(i.unit_price_cents)}${NBSP}${UNIT_SUFFIX[i.sale_unit]}`;
}

export function ReviewStep({ params, notes, onNotesChange, onEdit, onShippingConflict, onPlaced, retryDelaysMs }: Props) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const key = checkoutKeys.preview(params);
  const preview = useQuery({ queryKey: key, queryFn: () => fetchCheckoutPreview(params), staleTime: 0, retry: false });
  const { submit, phase, isPending } = usePlaceOrder(retryDelaysMs);
  const [accepted, setAccepted] = useState(false);
  const [termsError, setTermsError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<Conflict | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [changedTotal, setChangedTotal] = useState(false);
  const handledQuote = useRef<string | null>(null);

  const summary = preview.data;

  // Divergência de frete detectada na prévia → volta ao passo Frete com a nova cotação.
  useEffect(() => {
    const q = summary?.shipping_quote;
    if (q && q.quote_id && handledQuote.current !== q.quote_id) {
      handledQuote.current = q.quote_id;
      const msg = summary.blocking.find((b) => b.code.startsWith('shipping_'))?.message ?? 'O valor do frete foi atualizado. Escolha a entrega novamente.';
      onShippingConflict(q, msg);
    }
  }, [summary, onShippingConflict]);

  // Aviso de saída enquanto o pedido está em voo.
  useEffect(() => {
    if (!isPending) return;
    const h = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [isPending]);

  if (preview.isLoading) {
    return (
      <Box aria-busy="true">
        <StepHeading>Revisão do pedido</StepHeading>
        <Skeleton height={80} />
        <Skeleton height={80} />
        <Skeleton height={160} />
      </Box>
    );
  }
  if (preview.error || !summary) {
    const e = preview.error ? toApiError(preview.error) : null;
    if (e?.fieldErrors.address_uuid) {
      return (
        <Alert severity="error" action={<Button color="inherit" onClick={() => onEdit('endereco')}>Escolher endereço</Button>}>
          {e.fieldErrors.address_uuid[0]}
        </Alert>
      );
    }
    return <ErrorState title="Não foi possível carregar a revisão." error={preview.error} onRetry={() => void preview.refetch()} />;
  }

  const handleError = (err: ApiError) => {
    const units: Record<number, SaleUnit> = Object.fromEntries(summary.items.map((i) => [i.variant_id, i.sale_unit]));
    if (isShippingCode(err.code)) {
      resetIdempotencyKey();
      onShippingConflict(err.extra<ShippingQuote>('shipping_quote') ?? null, err.message);
      return;
    }
    switch (err.code) {
      case 'price_changed': {
        const next = err.extra<CheckoutSummary>('summary');
        resetIdempotencyKey();
        if (next) {
          setConflict({ kind: 'price_changed', previous: summary, summary: next });
          // Mescla o resumo novo no preview (a API envia CheckoutSummary completo).
          qc.setQueryData<CheckoutSummary>(key, (old) => (old ? { ...old, ...next, totals: { ...old.totals, ...next.totals } } : next));
        } else void preview.refetch();
        return;
      }
      case 'insufficient_stock':
        setConflict({ kind: 'insufficient_stock', items: err.extra<StockIssue[]>('items') ?? [], units });
        return;
      case 'coupon_invalid':
        resetIdempotencyKey();
        setConflict({ kind: 'coupon_invalid', coupon: err.extra<CouponIssue>('coupon') ?? null, summary: err.extra<CheckoutSummary>('summary') ?? null });
        return;
      case 'cart_invalid':
        setConflict({ kind: 'cart_invalid', items: err.extra<CartItemIssue[]>('items') ?? [], message: err.message });
        return;
      case 'cart_empty':
        navigate('/carrinho', { replace: true });
        return;
      case 'too_many_pending_orders':
        setConflict({ kind: 'too_many_pending_orders', orders: err.extra<{ uuid: string; number: string }[]>('pending_orders') ?? [], message: err.message });
        return;
      case 'idempotency_conflict':
        resetIdempotencyKey();
        setConflict({ kind: 'idempotency_conflict', order: err.extra<{ uuid: string; number: string }>('order') ?? null, message: err.message });
        return;
      case 'payment_gateway_unavailable': {
        const order = err.extra<{ uuid: string }>('order');
        if (order) {
          onPlaced(order.uuid);
          return;
        }
        break;
      }
      case 'unauthenticated':
        navigate(`/entrar?redirect=${encodeURIComponent('/checkout?passo=revisao')}`);
        return;
      default:
        break;
    }
    if (err.isValidation) {
      if (err.fieldErrors.address_uuid) return onEdit('endereco', err.fieldErrors.address_uuid[0]);
      if (err.fieldErrors.profile) return onEdit('identificacao', err.fieldErrors.profile[0]);
      if (err.fieldErrors.shipping_quote_id || err.fieldErrors.shipping_option_id) return onEdit('frete', err.message);
      if (err.fieldErrors.accept_terms) return setTermsError(err.fieldErrors.accept_terms[0]);
      if (err.fieldErrors.notes) return setError(err.fieldErrors.notes[0]);
    }
    if (err.code === 'too_many_requests') return setError('Muitas tentativas. Aguarde um minuto e tente novamente.');
    if (err.isNetwork) return setError('Não recebemos a confirmação. Tente novamente — seu pedido não será duplicado.');
    setError(describeError(err));
  };

  const confirm = async () => {
    setError(null);
    if (!accepted) {
      setTermsError('Aceite os termos para continuar');
      return;
    }
    if (!params.quoteId || !params.optionId) return onEdit('frete');
    try {
      const result = await submit({
        address_uuid: params.addressUuid,
        shipping_quote_id: params.quoteId,
        shipping_option_id: params.optionId,
        payment_method: 'pix',
        expected_total_cents: summary.totals.total_cents,
        notes: notes.trim() ? notes.trim() : null,
        accept_terms: true,
      });
      onPlaced(result.order.uuid);
    } catch (err) {
      handleError(toApiError(err));
    }
  };

  const option = summary.shipping_option;
  const billing = summary.billing;
  return (
    <Box>
      <StepHeading>Revisão do pedido</StepHeading>
      {summary.blocking.length ? (
        <Alert severity="warning" sx={{ mb: 3 }}>
          {summary.blocking.map((b) => (
            <div key={b.code}>{b.message}</div>
          ))}
        </Alert>
      ) : null}
      <Typography variant="h4" component="h3" sx={{ mb: 2 }}>Itens</Typography>
      <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }}>
        {summary.items.map((i) => (
          <Box component="li" key={i.id} sx={{ py: 2, borderBottom: 1, borderColor: 'divider', bgcolor: changedTotal && i.warnings.some((w) => w.code === 'price_changed') ? 'warning.light' : undefined }}>
            <Typography sx={{ fontWeight: 600 }}>
              {i.product.name} · {i.variant.name} · SKU {i.variant.sku}
            </Typography>
            {i.sale_unit === 'SQUARE_METER' ? (
              <Typography variant="body2" className="num">
                {i.configuration_label}
                {i.area_m2 !== null ? ` = ${formatArea(i.area_m2)}` : ''}
              </Typography>
            ) : null}
            <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
              <Typography variant="body2" className="num">{lineMath(i)}</Typography>
              <Typography className="num" sx={{ fontWeight: 600 }}>{i.line_total_cents !== null ? formatBRL(i.line_total_cents) : '—'}</Typography>
            </Box>
          </Box>
        ))}
      </Box>
      <Box component="dl" sx={{ display: 'grid', gridTemplateColumns: 'max-content 1fr auto', columnGap: 4, rowGap: 2, my: 4, '& dt': { fontWeight: 600 }, '& dd': { m: 0 } }}>
        <dt>Entrega</dt>
        <dd>{option ? `${option.name} — ${option.delivery_label}` : 'a definir'}</dd>
        <dd><Link component="button" type="button" onClick={() => onEdit('frete')}>Alterar</Link></dd>
        <dt>Endereço</dt>
        <dd>{summary.address.formatted}</dd>
        <dd><Link component="button" type="button" onClick={() => onEdit('endereco')}>Alterar</Link></dd>
        <dt>Pagamento</dt>
        <dd>PIX (expira {summary.payment_expires_in_minutes} min após confirmar)</dd>
        <dd><Link component="button" type="button" onClick={() => onEdit('pagamento')}>Alterar</Link></dd>
        <dt>Faturamento</dt>
        <dd>{billing.company_name ?? billing.name} · {billing.customer_type === 'company' ? 'CNPJ' : 'CPF'} {formatDocument(billing.document)}</dd>
        <dd />
      </Box>
      <Divider sx={{ mb: 3 }} />
      <SummaryPanel
        subtotalCents={summary.totals.subtotal_cents}
        discountCents={summary.totals.discount_cents}
        couponCode={summary.coupon?.code}
        shippingCents={summary.totals.shipping_cents}
        shippingDiscountCents={summary.totals.shipping_discount_cents}
        totalCents={summary.totals.total_cents}
        weightGrams={summary.total_weight_grams}
        busy={preview.isFetching}
      />
      <TextField
        label="Observações do pedido"
        multiline
        minRows={2}
        value={notes}
        onChange={(e) => onNotesChange(e.target.value.slice(0, 500))}
        helperText={`${notes.length}/500`}
        sx={{ my: 4 }}
      />
      <FormControlLabel
        control={<Checkbox checked={accepted} onChange={(e) => { setAccepted(e.target.checked); setTermsError(null); }} slotProps={{ input: { 'aria-describedby': termsError ? 'terms-error' : undefined } }} />}
        label={
          <>
            Li e aceito os <Link component={RouterLink} to="/institucional/termos" target="_blank">termos de compra</Link> e a{' '}
            <Link component={RouterLink} to="/institucional/trocas" target="_blank">política de trocas</Link>*
          </>
        }
      />
      {termsError ? <FormHelperText error id="terms-error">{termsError}</FormHelperText> : null}
      {error ? <Alert severity="error" role="alert" sx={{ mt: 3 }}>{error}</Alert> : null}
      <Box aria-busy={isPending ? 'true' : 'false'} sx={{ mt: 4, display: 'flex', flexDirection: 'column', gap: 2 }}>
        {phase === 'verifying' ? <Alert severity="info">Não recebemos a confirmação. Verificando seu pedido…</Alert> : null}
        <Button color="secondary" size="large" fullWidth onClick={confirm} disabled={!summary.can_place_order || isPending} loading={isPending} loadingPosition="start">
          {isPending ? 'Confirmando pedido…' : `Confirmar pedido · ${formatBRL(summary.totals.total_cents)}`}
        </Button>
      </Box>
      <ConflictDialog
        conflict={conflict}
        onClose={() => setConflict(null)}
        onReview={() => {
          setConflict(null);
          setChangedTotal(true);
        }}
        onBackToCart={() => navigate('/carrinho')}
        onRemoveCoupon={async () => {
          try {
            const cart = await removeCoupon();
            storeCart(qc, cart);
          } finally {
            setConflict(null);
            void preview.refetch();
          }
        }}
      />
    </Box>
  );
}
