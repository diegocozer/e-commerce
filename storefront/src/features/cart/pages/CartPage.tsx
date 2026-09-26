import ShoppingCartOutlined from '@mui/icons-material/ShoppingCartOutlined';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Collapse from '@mui/material/Collapse';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState, type FormEvent } from 'react';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router';
import { useAuth } from '@/features/auth';
import { ReorderSuggestions } from '@/features/account';
import { toApiError } from '@/shared/api/errors';
import type { ReorderReport } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatCEP, isValidCEP, onlyDigits } from '@/shared/formatters/postalCode';
import { usePreferredPostalCode } from '@/shared/hooks/usePostalCode';
import { EmptyState } from '@/shared/ui/EmptyState';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { ShippingOptionRadios } from '@/shared/ui/ShippingOptions';
import { SummaryPanel } from '@/shared/ui/SummaryPanel';
import { CartItemRow } from '../components/CartItemRow';
import { CouponForm } from '../components/CouponForm';
import { FreeShippingProgress } from '../components/FreeShippingProgress';
import { useCart, useCartMutations } from '../hooks/useCart';
import { useCartShipping } from '../hooks/useCartShipping';

function ReorderReportAlert({ report }: { report: ReorderReport }) {
  const [open, setOpen] = useState(false);
  const { summary } = report;
  const done = summary.added_items + summary.adjusted_items;
  return (
    <Alert severity={summary.skipped_items ? 'warning' : 'success'} sx={{ mb: 3 }} action={<Button color="inherit" size="small" onClick={() => setOpen((v) => !v)}>{open ? 'Ocultar' : 'Detalhes'}</Button>}>
      <AlertTitle>
        {done} de {summary.total_items} itens adicionados
      </AlertTitle>
      <Collapse in={open}>
        <ul style={{ margin: 0, paddingLeft: 16 }}>
          {report.items.map((i) => (
            <li key={i.sku}>
              {i.result === 'added' ? '✓' : i.result === 'adjusted' ? '⚠' : '✖'} {i.product_name}: {i.message}
              {i.current_unit_price_cents !== null && i.current_unit_price_cents !== i.previous_unit_price_cents
                ? ` (preço atual ${formatBRL(i.current_unit_price_cents)}; no pedido anterior: ${formatBRL(i.previous_unit_price_cents)})`
                : ''}
              {i.result === 'invalid_rules' && i.product_url_path ? (
                <>
                  {' '}
                  <Link component={RouterLink} to={i.product_url_path}>Ver produto</Link>
                </>
              ) : null}
            </li>
          ))}
        </ul>
      </Collapse>
    </Alert>
  );
}

export default function CartPage() {
  const { isAuthenticated } = useAuth();
  const [preferred, setPreferred] = usePreferredPostalCode();
  const base = useCart();
  const hasItems = Boolean(base.data?.items.length);
  const shipping = useCartShipping(preferred, hasItems);
  const withSel = useCart(shipping.selection);
  const cart = shipping.selection ? (withSel.data ?? base.data) : base.data;
  const { acknowledge } = useCartMutations();
  const navigate = useNavigate();
  const location = useLocation();
  const reorderReport = (location.state as { reorder?: ReorderReport } | null)?.reorder ?? null;
  const [cepInput, setCepInput] = useState(formatCEP(shipping.postalCode ?? preferred ?? ''));
  const [cepError, setCepError] = useState<string | null>(null);

  if (base.isLoading) {
    return (
      <Box aria-busy="true">
        <Seo title="Carrinho" robots="noindex,follow" />
        <PageHeading>Carrinho</PageHeading>
        <Grid container spacing={6}>
          <Grid size={{ xs: 12, md: 8 }}>
            <Skeleton variant="rectangular" height={120} sx={{ mb: 2 }} />
            <Skeleton variant="rectangular" height={120} />
          </Grid>
          <Grid size={{ xs: 12, md: 4 }}>
            <Skeleton variant="rectangular" height={240} />
          </Grid>
        </Grid>
      </Box>
    );
  }
  if (base.error || !cart) {
    return (
      <>
        <Seo title="Carrinho" robots="noindex,follow" />
        <ErrorState title="Não foi possível carregar seu carrinho." error={base.error} onRetry={() => void base.refetch()} />
      </>
    );
  }

  if (!cart.items.length) {
    return (
      <Box>
        <Seo title="Carrinho" robots="noindex,follow" />
        <PageHeading>Carrinho</PageHeading>
        {reorderReport ? <ReorderReportAlert report={reorderReport} /> : null}
        <EmptyState
          icon={<ShoppingCartOutlined />}
          title="Seu carrinho está vazio"
          text="Encontre lonas, vinis, adesivos e tudo para sua produção."
          action={<Button component={RouterLink} to="/">Ver categorias</Button>}
        />
        {isAuthenticated ? (
          <Card sx={{ p: 4, maxWidth: 560, mx: 'auto' }}>
            <Typography variant="h3" component="h2" sx={{ mb: 3 }}>Compre de novo</Typography>
            <ReorderSuggestions limit={4} />
          </Card>
        ) : null}
      </Box>
    );
  }

  const quoteErr = shipping.quote.error ? toApiError(shipping.quote.error) : null;
  const selectionInvalid = cart.shipping_selection && !cart.shipping_selection.valid;
  const goCheckout = () => navigate(isAuthenticated ? '/checkout' : '/entrar?redirect=%2Fcheckout');
  const focusFirstProblem = () => {
    const el = document.querySelector<HTMLElement>('[data-blocked="true"]');
    el?.scrollIntoView({ block: 'center' });
    el?.focus();
  };
  const submitCep = (e: FormEvent) => {
    e.preventDefault();
    if (!isValidCEP(cepInput)) {
      setCepError('CEP inválido. Use 8 dígitos, ex.: 89010-000');
      return;
    }
    setCepError(null);
    const d = onlyDigits(cepInput);
    setPreferred(d);
    shipping.request(d);
  };
  const busy = Boolean(shipping.selection && withSel.isFetching);

  return (
    <Box sx={{ pb: { xs: 24, md: 0 } }}>
      <Seo title="Carrinho" robots="noindex,follow" />
      <PageHeading>
        Carrinho ({cart.items_count} {cart.items_count === 1 ? 'item' : 'itens'})
      </PageHeading>
      {reorderReport ? <ReorderReportAlert report={reorderReport} /> : null}
      {cart.has_price_changes ? (
        <Alert severity="warning" sx={{ mb: 3 }} action={<Button color="inherit" size="small" onClick={() => acknowledge.mutate()} loading={acknowledge.isPending}>Entendi</Button>}>
          O preço de {cart.items.filter((i) => i.warnings.some((w) => w.code === 'price_changed')).length} item(ns) mudou desde que você o adicionou.
        </Alert>
      ) : null}
      <Grid container spacing={6}>
        <Grid size={{ xs: 12, md: 8 }}>
          <Card>
            {cart.items.map((item) => (
              <CartItemRow key={item.id} item={item} />
            ))}
          </Card>
          <Link component={RouterLink} to="/" sx={{ display: 'inline-block', mt: 3 }}>
            Continuar comprando
          </Link>
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <Card sx={{ p: 4, position: { md: 'sticky' }, top: { md: 16 }, display: 'flex', flexDirection: 'column', gap: 4 }}>
            <CouponForm coupon={cart.coupon} />
            <Box component="section" aria-labelledby="cart-frete">
              <Typography variant="h4" component="h2" id="cart-frete" sx={{ mb: 2 }}>
                Frete
              </Typography>
              <Box component="form" onSubmit={submitCep} sx={{ display: 'flex', gap: 2, alignItems: 'flex-start', mb: 3 }} noValidate>
                <TextField size="small" label="CEP" value={cepInput} onChange={(e) => setCepInput(formatCEP(e.target.value))} error={Boolean(cepError)} helperText={cepError ?? undefined} slotProps={{ htmlInput: { inputMode: 'numeric', autoComplete: 'postal-code', maxLength: 9 } }} />
                <Button type="submit" variant="outlined" size="medium" loading={shipping.quote.isFetching}>
                  Calcular
                </Button>
              </Box>
              {quoteErr ? (
                <Alert severity="error">{quoteErr.code === 'too_many_requests' ? 'Muitas consultas seguidas. Aguarde alguns segundos.' : quoteErr.isValidation ? 'Não encontramos esse CEP.' : 'Não foi possível calcular o frete agora.'}</Alert>
              ) : shipping.quote.data ? (
                shipping.quote.data.options.length ? (
                  <>
                    {shipping.quote.data.notice === 'pickup_only_items' ? (
                      <Alert severity="info" sx={{ mb: 2 }}>Seu carrinho tem itens disponíveis só para retirada.</Alert>
                    ) : null}
                    <ShippingOptionRadios name="cart-shipping" legend="Opções de frete" options={shipping.quote.data.options} value={shipping.optionId} onChange={shipping.choose} />
                  </>
                ) : (
                  <Alert severity="info">{shipping.quote.data.message ?? 'Não há opções de entrega para este CEP.'}</Alert>
                )
              ) : null}
              {selectionInvalid ? <Alert severity="warning" sx={{ mt: 2 }}>A cotação expirou. Calcule o frete novamente.</Alert> : null}
            </Box>
            <SummaryPanel
              subtotalCents={cart.totals.subtotal_cents}
              itemsCount={cart.items_count}
              discountCents={cart.totals.discount_cents}
              couponCode={cart.coupon?.valid ? cart.coupon.code : null}
              shippingCents={cart.totals.shipping_cents}
              shippingDiscountCents={cart.totals.shipping_discount_cents}
              shippingLabel="Frete (estimado)"
              totalCents={cart.totals.total_cents}
              weightGrams={cart.total_weight_grams}
              busy={busy}
            />
            <FreeShippingProgress progress={cart.free_shipping_progress} />
            <Button color="secondary" fullWidth disabled={!cart.can_checkout} onClick={goCheckout}>
              Finalizar compra
            </Button>
            {!cart.can_checkout ? (
              <Link component="button" type="button" onClick={focusFirstProblem} variant="body2" sx={{ textAlign: 'left' }}>
                Resolva os itens destacados para continuar
              </Link>
            ) : null}
          </Card>
        </Grid>
      </Grid>
      <Paper elevation={8} sx={{ display: { xs: 'flex', md: 'none' }, position: 'fixed', left: 0, right: 0, bottom: 0, zIndex: 10, p: 3, alignItems: 'center', justifyContent: 'space-between' }}>
        <Typography variant="total" component="p">
          {formatBRL(cart.totals.total_cents)}
        </Typography>
        <Button color="secondary" disabled={!cart.can_checkout} onClick={goCheckout}>
          Finalizar compra
        </Button>
      </Paper>
    </Box>
  );
}
