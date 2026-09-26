import LocationOnOutlined from '@mui/icons-material/LocationOnOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Skeleton from '@mui/material/Skeleton';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { toApiError } from '@/shared/api/errors';
import { formatCEP, isValidCEP, onlyDigits } from '@/shared/formatters/postalCode';
import { formatWeight } from '@/shared/formatters/quantity';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { usePreferredPostalCode } from '@/shared/hooks/usePostalCode';
import type { LineBody } from '@/shared/saleUnit/configuration';
import { ShippingOptionList } from '@/shared/ui/ShippingOptions';
import { catalogKeys, fetchShippingEstimate } from '../api';

/** Calculadora de frete do produto (UX §4.4.6) — cota só este item, após o usuário pedir. */
export function ShippingEstimator({ variantId, line, configurationLabel }: { variantId: number; line: LineBody | null; configurationLabel: string }) {
  const [preferred, setPreferred] = usePreferredPostalCode();
  const [input, setInput] = useState(formatCEP(preferred ?? ''));
  const [requested, setRequested] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const debouncedLine = useDebounce(line, 600);

  const items = debouncedLine ? [{ variant_id: variantId, ...debouncedLine }] : null;
  const q = useQuery({
    queryKey: catalogKeys.shippingEstimate(requested ?? '', items),
    queryFn: () => fetchShippingEstimate(requested!, items!),
    enabled: Boolean(requested && items),
    retry: false,
    staleTime: 60_000,
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!isValidCEP(input)) {
      setFormError('CEP inválido. Use 8 dígitos, ex.: 89010-000');
      return;
    }
    setFormError(null);
    const cep = onlyDigits(input);
    setPreferred(cep);
    setRequested(cep);
  };

  const err = q.error ? toApiError(q.error) : null;
  let errorText: string | null = null;
  if (err) {
    if (err.code === 'too_many_requests') errorText = 'Muitas consultas seguidas. Aguarde alguns segundos.';
    else if (err.isValidation) errorText = err.fieldMessage('postal_code') ? 'Não encontramos esse CEP.' : err.message;
    else errorText = 'Não foi possível calcular o frete agora.';
  }

  return (
    <Box component="section" aria-labelledby="frete-produto">
      <Typography variant="h4" component="h2" id="frete-produto" sx={{ mb: 2 }}>
        Calcular frete
      </Typography>
      <Box component="form" onSubmit={submit} sx={{ display: 'flex', gap: 2, alignItems: 'flex-start' }} noValidate>
        <TextField
          label="CEP"
          value={input}
          onChange={(e) => setInput(formatCEP(e.target.value))}
          error={Boolean(formError)}
          helperText={formError ?? undefined}
          sx={{ maxWidth: 200 }}
          slotProps={{ htmlInput: { inputMode: 'numeric', autoComplete: 'postal-code', maxLength: 9 } }}
        />
        <Button type="submit" variant="outlined" sx={{ mt: 1 }} disabled={!line} startIcon={<LocationOnOutlined />}>
          Calcular
        </Button>
      </Box>
      <Box aria-live="polite" sx={{ mt: 3 }}>
        {q.isFetching ? (
          <Box aria-busy="true">
            <Skeleton height={56} />
            <Skeleton height={56} />
          </Box>
        ) : errorText ? (
          <Alert severity="error" action={<Button color="inherit" size="small" onClick={() => void q.refetch()}>Tentar novamente</Button>}>
            {errorText}
          </Alert>
        ) : q.data ? (
          <>
            {q.data.destination.city ? (
              <Typography variant="body2" sx={{ mb: 2 }}>
                {q.data.destination.city}/{q.data.destination.state}
              </Typography>
            ) : null}
            {q.data.options.length ? (
              <ShippingOptionList options={q.data.options} />
            ) : (
              <Alert severity="info">{q.data.message ?? 'Não entregamos neste CEP. Fale conosco no WhatsApp.'}</Alert>
            )}
            <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 2 }}>
              Frete calculado para {configurationLabel} ({formatWeight(q.data.total_weight_grams)}). Valor estimado para este item; o frete final é calculado no carrinho.
            </Typography>
          </>
        ) : null}
      </Box>
    </Box>
  );
}
