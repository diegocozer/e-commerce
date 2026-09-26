import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import Grid from '@mui/material/Grid';
import LinearProgress from '@mui/material/LinearProgress';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import Step from '@mui/material/Step';
import StepButton from '@mui/material/StepButton';
import Stepper from '@mui/material/Stepper';
import Typography from '@mui/material/Typography';
import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, Navigate, useSearchParams } from 'react-router';
import { useAuth } from '@/features/auth';
import { useCart } from '@/features/cart';
import { useSettings } from '@/features/catalog/hooks/queries';
import type { ShippingQuote } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { SummaryPanel } from '@/shared/ui/SummaryPanel';
import { AddressStep } from '../components/AddressStep';
import { IdentificationStep } from '../components/IdentificationStep';
import { PaymentStep } from '../components/PaymentStep';
import { ReviewStep } from '../components/ReviewStep';
import { ShippingStep } from '../components/ShippingStep';
import { isQuoteExpired, useCheckoutState } from '../hooks/useCheckoutState';

const STEPS = [
  { key: 'identificacao', label: 'Identificação' },
  { key: 'endereco', label: 'Endereço' },
  { key: 'frete', label: 'Frete' },
  { key: 'pagamento', label: 'Pagamento' },
  { key: 'revisao', label: 'Revisão' },
] as const;
type StepKey = (typeof STEPS)[number]['key'];

export default function CheckoutPage({ retryDelaysMs }: { retryDelaysMs?: number[] }) {
  const { customer } = useAuth();
  const cart = useCart();
  const settings = useSettings();
  const [params, setParams] = useSearchParams();
  const { state, update, reset } = useCheckoutState();
  const [notice, setNotice] = useState<string | null>(null);
  // Pedido criado: congela o passo e o redirecionamento para o carrinho (o carrinho
  // fica vazio e o reset do estado mudaria `passo`, sobrescrevendo a navegação).
  const [placedUuid, setPlacedUuid] = useState<string | null>(null);
  const selection = state.quote?.quote_id && state.optionId ? { quoteId: state.quote.quote_id, optionId: state.optionId } : null;
  const cartWithShipping = useCart(selection);

  const done: Record<StepKey, boolean> = {
    identificacao: Boolean(customer?.profile_complete),
    endereco: Boolean(state.addressUuid),
    frete: Boolean(state.quote && state.optionId && !isQuoteExpired(state.quote)),
    pagamento: state.paymentConfirmed,
    revisao: false,
  };
  const firstPending = STEPS.find((s) => !done[s.key])?.key ?? 'revisao';
  const requested = (params.get('passo') as StepKey | null) ?? firstPending;
  const reqIndex = STEPS.findIndex((s) => s.key === requested);
  const pendingIndex = STEPS.findIndex((s) => s.key === firstPending);
  // Não pular passos não concluídos (UX §4.6).
  const current: StepKey = reqIndex < 0 || reqIndex > pendingIndex ? firstPending : requested;
  const currentIndex = STEPS.findIndex((s) => s.key === current);

  const go = useCallback(
    (step: StepKey, msg?: string | null) => {
      setNotice(msg ?? null);
      const next = new URLSearchParams(params);
      next.set('passo', step);
      setParams(next);
    },
    [params, setParams],
  );

  useEffect(() => {
    if (!customer || placedUuid) return; // aguarda /me antes de decidir o passo
    if (params.get('passo') !== current) {
      const next = new URLSearchParams(params);
      next.set('passo', current);
      setParams(next, { replace: true });
    }
  }, [current, params, setParams, customer, placedUuid]);

  const onSelectAddress = useCallback((uuid: string) => update((s) => (s.addressUuid === uuid ? {} : { addressUuid: uuid, quote: null, optionId: null })), [update]);
  const onShippingConflict = useCallback(
    (quote: ShippingQuote | null, message: string) => {
      update({ quote, optionId: null });
      go('frete', message);
    },
    [update, go],
  );

  if (placedUuid) return <Navigate to={`/checkout/pedido/${placedUuid}`} replace />;
  if (!customer) return null;
  if (cart.isLoading) return <Skeleton variant="rectangular" height={320} />;
  if (cart.error) return <ErrorState error={cart.error} onRetry={() => void cart.refetch()} />;
  if (cart.data && cart.data.items.length === 0) return <Navigate to="/carrinho" replace />;

  const c = (selection ? cartWithShipping.data : undefined) ?? cart.data!;
  const title = STEPS[currentIndex].label;
  const shippingOption = state.quote?.options.find((o) => o.option_id === state.optionId) ?? null;

  return (
    <Box>
      <Seo title={`${title} — Checkout`} robots="noindex,nofollow" />
      <PageHeading focus={false} sx={{ fontSize: { xs: 22, md: 28 } }}>
        Finalizar compra
      </PageHeading>
      <Box sx={{ display: { xs: 'block', md: 'none' }, mb: 4 }}>
        <Typography variant="body2" sx={{ mb: 1 }}>
          Passo {Math.min(currentIndex + 1, 5)} de 5 · {title}
        </Typography>
        <LinearProgress variant="determinate" value={((currentIndex + 1) / 5) * 100} aria-label="Progresso do checkout" />
      </Box>
      <Stepper nonLinear activeStep={currentIndex} sx={{ display: { xs: 'none', md: 'flex' }, mb: 6 }}>
        {STEPS.map((s, i) => (
          <Step key={s.key} completed={done[s.key] && i < currentIndex}>
            <StepButton onClick={() => go(s.key)} disabled={i > pendingIndex} aria-current={i === currentIndex ? 'step' : undefined}>
              {s.label}
            </StepButton>
          </Step>
        ))}
      </Stepper>
      <Grid container spacing={6}>
        <Grid size={{ xs: 12, md: 8 }}>
          <Card sx={{ p: { xs: 4, md: 6 } }}>
            {current === 'identificacao' ? <IdentificationStep customer={customer} onContinue={() => go('endereco')} /> : null}
            {current === 'endereco' ? (
              <AddressStep customer={customer} selected={state.addressUuid} onSelect={onSelectAddress} notice={notice} onBack={() => go('identificacao')} onContinue={() => go('frete')} />
            ) : null}
            {current === 'frete' && state.addressUuid ? (
              <ShippingStep
                addressUuid={state.addressUuid}
                quote={state.quote}
                optionId={state.optionId}
                notice={notice}
                onQuote={(quote, preselect, changed) => {
                  update({ quote, optionId: preselect });
                  if (changed) setNotice('O valor do frete foi atualizado');
                }}
                onChoose={(optionId) => update({ optionId })}
                onBack={() => go('endereco')}
                onContinue={() => go('pagamento')}
                onChangeAddress={() => go('endereco')}
              />
            ) : null}
            {current === 'pagamento' ? (
              <PaymentStep
                expiryMinutes={settings.data?.checkout.pix_expiry_minutes ?? 30}
                onBack={() => go('frete')}
                onContinue={() => {
                  update({ paymentConfirmed: true });
                  go('revisao');
                }}
              />
            ) : null}
            {current === 'revisao' && state.addressUuid ? (
              <ReviewStep
                params={{ addressUuid: state.addressUuid, quoteId: state.quote?.quote_id ?? null, optionId: state.optionId }}
                notes={state.notes}
                onNotesChange={(notes) => update({ notes })}
                onEdit={(step, msg) => go(step, msg)}
                onShippingConflict={onShippingConflict}
                onPlaced={(uuid) => {
                  reset();
                  setPlacedUuid(uuid);
                }}
                retryDelaysMs={retryDelaysMs}
              />
            ) : null}
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <Card sx={{ p: 4, position: { md: 'sticky' }, top: { md: 16 } }} component="aside" aria-label="Resumo do pedido">
            <Typography variant="h4" component="h2" sx={{ mb: 3 }}>
              Resumo do pedido
            </Typography>
            <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, mb: 3 }}>
              {c.items.map((i) => (
                <Box component="li" key={i.id} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, py: 1 }}>
                  <Box>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{i.product.name}</Typography>
                    <Typography variant="caption" color="text.secondary">{i.configuration_label}</Typography>
                  </Box>
                  <Typography variant="body2" className="num">{i.line_total_cents !== null ? formatBRL(i.line_total_cents) : '—'}</Typography>
                </Box>
              ))}
            </Box>
            <SummaryPanel
              subtotalCents={c.totals.subtotal_cents}
              discountCents={c.totals.discount_cents}
              couponCode={c.coupon?.valid ? c.coupon.code : null}
              shippingCents={c.totals.shipping_cents}
              shippingDiscountCents={c.totals.shipping_discount_cents}
              totalCents={c.totals.total_cents}
              weightGrams={c.total_weight_grams}
            />
            {shippingOption ? (
              <Typography variant="caption" color="text.secondary">
                Entrega: {shippingOption.name}. O valor final é confirmado na revisão.
              </Typography>
            ) : null}
            <Link component={RouterLink} to="/carrinho" sx={{ display: 'block', mt: 3 }}>
              Editar carrinho
            </Link>
          </Card>
        </Grid>
      </Grid>
    </Box>
  );
}
