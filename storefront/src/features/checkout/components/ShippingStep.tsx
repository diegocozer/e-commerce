import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { useMutation } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { quoteCartShipping, readCartShippingChoice } from '@/features/cart';
import { describeError, toApiError } from '@/shared/api/errors';
import type { ShippingQuote } from '@/shared/api/types';
import { formatWeight } from '@/shared/formatters/quantity';
import { ShippingOptionRadios } from '@/shared/ui/ShippingOptions';
import { isQuoteExpired } from '../hooks/useCheckoutState';
import { StepHeading } from './StepHeading';

export function ShippingStep({ addressUuid, quote, optionId, notice, onQuote, onChoose, onBack, onContinue, onChangeAddress }: {
  addressUuid: string;
  quote: ShippingQuote | null;
  optionId: string | null;
  notice?: string | null;
  onQuote: (q: ShippingQuote, preselect: string | null, changed: boolean) => void;
  onChoose: (optionId: string) => void;
  onBack: () => void;
  onContinue: () => void;
  onChangeAddress: () => void;
}) {
  const quoteFor = useRef<string | null>(null);
  const m = useMutation({ mutationFn: () => quoteCartShipping({ address_uuid: addressUuid }) });

  useEffect(() => {
    // Cotação automática para o endereço escolhido; recota se expirada (TTL 30 min).
    const stale = !quote || isQuoteExpired(quote) || quoteFor.current !== addressUuid;
    if (!stale || m.isPending) return;
    const previous = quote;
    quoteFor.current = addressUuid;
    m.mutate(undefined, {
      onSuccess: (q) => {
        const wanted = optionId ?? readCartShippingChoice()?.optionId ?? null;
        const preselect = wanted && q.options.some((o) => o.option_id === wanted) ? wanted : null;
        const prevOpt = previous?.options.find((o) => o.option_id === wanted);
        const newOpt = q.options.find((o) => o.option_id === wanted);
        onQuote(q, preselect, Boolean(prevOpt && newOpt && prevOpt.price_cents !== newOpt.price_cents));
      },
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [addressUuid]);

  const err = m.error ? toApiError(m.error) : null;
  return (
    <Box>
      <StepHeading>Frete</StepHeading>
      {notice ? <Alert severity="warning" sx={{ mb: 3 }}>{notice}</Alert> : null}
      {m.isPending ? (
        <Box aria-busy="true">
          <span className="visually-hidden">Calculando frete</span>
          {[0, 1, 2].map((i) => <Skeleton key={i} variant="rectangular" height={72} sx={{ mb: 2, borderRadius: 2 }} />)}
        </Box>
      ) : err ? (
        <Alert severity="error" action={<Button color="inherit" size="small" onClick={() => m.mutate(undefined, { onSuccess: (q) => onQuote(q, null, false) })}>Tentar novamente</Button>}>
          {err.code === 'cart_empty' ? 'Seu carrinho está vazio.' : describeError(err)}
        </Alert>
      ) : quote ? (
        quote.options.length ? (
          <>
            {quote.notice === 'pickup_only_items' ? <Alert severity="info" sx={{ mb: 2 }}>Seu carrinho tem itens disponíveis só para retirada.</Alert> : null}
            <ShippingOptionRadios name="checkout-shipping" legend="Opções de entrega" options={quote.options} value={optionId} onChange={onChoose} />
            {quote.options.find((o) => o.option_id === optionId)?.is_free ? (
              <Typography sx={{ color: 'success.main', mt: 2, fontWeight: 600 }}>Você ganhou frete grátis</Typography>
            ) : null}
            <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
              Peso total aprox.: {formatWeight(quote.total_weight_grams)} · Cotação válida por 30 minutos
            </Typography>
          </>
        ) : (
          <Alert severity="info" action={<Button color="inherit" size="small" onClick={onChangeAddress}>Escolher outro endereço</Button>}>
            {quote.message ?? 'Não há entrega disponível para este CEP.'}
          </Alert>
        )
      ) : null}
      <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 6 }}>
        <Button variant="outlined" onClick={onBack}>‹ Voltar</Button>
        <Button onClick={onContinue} disabled={!optionId || !quote || m.isPending}>Continuar ›</Button>
      </Box>
    </Box>
  );
}
