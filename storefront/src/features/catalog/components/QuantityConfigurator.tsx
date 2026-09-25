import AspectRatioOutlined from '@mui/icons-material/AspectRatioOutlined';
import ScaleOutlined from '@mui/icons-material/ScaleOutlined';
import StraightenOutlined from '@mui/icons-material/StraightenOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import { useEffect, useMemo, useState } from 'react';
import type { ApiError } from '@/shared/api/errors';
import type { LineConfiguration, PricePreview, ProductVariant } from '@/shared/api/types';
import { formatBRL, spokenBRL } from '@/shared/formatters/money';
import { formatAreaMilli, formatMetersMilli, formatPieces, formatQuantityMilli, formatWeight } from '@/shared/formatters/quantity';
import {
  checkConfiguration,
  computeLocalPreview,
  initialDraft,
  stepValue,
  type ConfigField,
  type LocalPreview,
  type QuantityDraft,
  type ValidConfiguration,
} from '@/shared/saleUnit/configuration';
import { formatMilli, stepDecimals, toMilli } from '@/shared/saleUnit/decimal';
import { INPUT_ADORNMENT, NBSP, QUANTITY_FIELD_LABEL, UNIT_SUFFIX, isIntegerUnit } from '@/shared/saleUnit/labels';
import { QuantityStepper } from '@/shared/ui/QuantityStepper';
import { usePricePreview } from '../hooks/usePricePreview';

export interface ConfiguratorState {
  valid: ValidConfiguration | null;
  /** Resposta do servidor para a configuração atual (verdade). */
  preview: PricePreview | null;
  local: LocalPreview | null;
  recalculating: boolean;
  /** Total a exibir: servidor se disponível; senão prévia local. */
  totalCents: number | null;
  stockInsufficient: boolean;
  blockedReason: string | null;
  configurationLabel: string;
}

interface Props {
  productSlug: string;
  productName: string;
  variant: ProductVariant;
  initialConfiguration?: LineConfiguration | null;
  onStateChange?: (state: ConfiguratorState) => void;
  /** Disponível informado por um 409 do carrinho (UX §4.4.7). */
  maxAvailable?: number | null;
  /** Erros de 422 do carrinho a exibir nos campos. */
  externalErrors?: Partial<Record<ConfigField, string>>;
}

const FIELD_OF: Record<string, ConfigField> = { quantity: 'quantity', width_m: 'width_m', height_m: 'height_m', pieces: 'pieces' };

function serverFieldErrors(error: ApiError | null): Partial<Record<ConfigField, { message: string; suggestions?: number[] }>> {
  if (!error || !error.isValidation) return {};
  const out: Partial<Record<ConfigField, { message: string; suggestions?: number[] }>> = {};
  for (const [k, msgs] of Object.entries(error.fieldErrors)) {
    const f = FIELD_OF[k];
    if (f) out[f] = { message: msgs[0], suggestions: error.details[k]?.suggestions };
  }
  if (!Object.keys(out).length) out.quantity = { message: error.message };
  return out;
}

/** Configurador por unidade de venda (UX §4.4.4). */
export function QuantityConfigurator({ productSlug, productName, variant, initialConfiguration, onStateChange, maxAvailable, externalErrors }: Props) {
  const rules = variant.rules;
  const unit = rules.sale_unit;
  const [draft, setDraft] = useState<QuantityDraft>(() => initialDraft(rules, initialConfiguration));
  const [touched, setTouched] = useState<Partial<Record<ConfigField, boolean>>>({});

  const check = useMemo(() => checkConfiguration(rules, draft), [rules, draft]);
  const valid = check.ok ? check : null;
  const local = useMemo(() => (valid ? computeLocalPreview(unit, valid, variant.price, variant.weight_grams) : null), [valid, unit, variant.price, variant.weight_grams]);
  const { preview, recalculating, error: serverError } = usePricePreview(productSlug, variant.id, valid);
  const serverErrors = serverFieldErrors(serverError);

  const localErrors = check.ok ? {} : check.errors;
  const errorFor = (f: ConfigField): { message: string; suggestions?: number[] } | null => {
    if (externalErrors?.[f]) return { message: externalErrors[f]! };
    const le = localErrors[f];
    if (le) {
      // "Informe…" só após interação (UX §6.5); valor inválido digitado aparece na hora.
      if (le.message.startsWith('Informe') && !touched[f]) return null;
      return { message: le.message, suggestions: le.suggestionsMilli?.map((m) => m / 1000) };
    }
    return serverErrors[f] ?? null;
  };

  const billableMilli = preview ? toMilli(preview.billable_quantity) : (valid?.billableMilli ?? null);
  const unitPrice = preview ? preview.unit_price_cents : (local?.unitPriceCents ?? variant.price.unit_price_cents);
  const totalCents = preview ? preview.line_total_cents : (local?.lineTotalCents ?? null);
  const weightGrams = preview ? preview.weight_grams : (local?.weightGrams ?? null);
  const areaMilli = preview?.area_m2 != null ? toMilli(preview.area_m2) : (valid?.areaMilli ?? null);
  const minAreaApplied = preview ? preview.min_area_applied : Boolean(valid?.minAreaApplied);

  const availableMilli = preview && !preview.stock.sufficient && preview.stock.available_quantity != null ? toMilli(preview.stock.available_quantity) : maxAvailable != null ? toMilli(maxAvailable) : null;
  const stockInsufficient = Boolean(preview && !preview.stock.sufficient) || (availableMilli !== null && valid !== null && valid.stockMilli > availableMilli);

  const configurationLabel = preview?.configuration_label ?? (valid ? labelFor(valid) : '');

  function labelFor(v: ValidConfiguration): string {
    if (unit === 'SQUARE_METER') {
      const c = v.configuration;
      return `${formatMetersMilli(toMilli(c.width_m ?? 0))} × ${formatMetersMilli(toMilli(c.height_m ?? 0))} × ${formatPieces(c.pieces ?? 1)}`;
    }
    return formatQuantityMilli(v.billableMilli, unit);
  }

  let blockedReason: string | null = null;
  if (variant.availability.status === 'out_of_stock') blockedReason = 'Indisponível';
  else if (!valid) blockedReason = unit === 'SQUARE_METER' && check.ok === false && check.errors.height_m ? 'Informe a altura' : 'Revise a quantidade';
  else if (serverError?.isValidation) blockedReason = 'Revise a quantidade';
  else if (stockInsufficient) blockedReason = 'Quantidade indisponível';

  const stateKey = `${valid ? JSON.stringify(valid.configuration) : 'x'}|${preview?.line_total_cents ?? ''}|${recalculating}|${stockInsufficient}|${blockedReason}|${local?.lineTotalCents ?? ''}`;
  useEffect(() => {
    onStateChange?.({ valid, preview, local, recalculating, totalCents, stockInsufficient, blockedReason, configurationLabel });
    // stateKey resume todas as dependências relevantes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stateKey]);

  const set = (field: keyof QuantityDraft) => (value: string) => setDraft((d) => ({ ...d, [field]: value }));
  const touch = (f: ConfigField) => () => setTouched((t) => ({ ...t, [f]: true }));

  const minQ = rules.min_quantity;
  const maxQ = rules.max_quantity;
  const stepMilli = toMilli(rules.quantity_step);
  const id = `cfg-${variant.id}`;

  const suggestionButtons = (field: 'quantity' | 'pieces', suggestions?: number[]) =>
    suggestions && suggestions.length ? (
      <Stack direction="row" spacing={2} sx={{ mt: 1 }}>
        {suggestions.map((s) => {
          const text = formatMilli(toMilli(s), field === 'quantity' && !isIntegerUnit(unit) ? Math.max(0, Math.min(2, stepDecimals(stepMilli))) : 0).replace(/\./g, '');
          return (
            <Button key={s} size="small" variant="outlined" onClick={() => set(field)(text)} aria-label={`Usar ${text}`}>
              Usar {text}
            </Button>
          );
        })}
      </Stack>
    ) : null;

  const qtyError = errorFor('quantity');
  const piecesError = errorFor('pieces');
  const widthError = errorFor('width_m');
  const heightError = errorFor('height_m');

  return (
    <Box component="section" aria-label="Configurar quantidade" sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {unit === 'SQUARE_METER' ? (
        <>
          {rules.fixed_width_m !== null ? (
            <Typography sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <StraightenOutlined fontSize="small" aria-hidden /> Largura: <strong className="num">{formatMetersMilli(toMilli(rules.fixed_width_m))}</strong> (fixa)
            </Typography>
          ) : (
            <TextField
              id={`${id}-width`}
              label="Largura (m)"
              value={draft.width}
              onChange={(e) => set('width')(e.target.value)}
              onBlur={touch('width_m')}
              error={Boolean(widthError)}
              helperText={widthError?.message ?? rangeText(rules.min_width_m, rules.max_width_m)}
              sx={{ maxWidth: 240 }}
              slotProps={{ htmlInput: { inputMode: 'decimal', className: 'num', autoComplete: 'off' }, input: { endAdornment: <InputAdornment position="end">m</InputAdornment> } }}
            />
          )}
          <TextField
            id={`${id}-height`}
            label="Altura (m)"
            required
            value={draft.height}
            onChange={(e) => set('height')(e.target.value)}
            onBlur={touch('height_m')}
            error={Boolean(heightError)}
            helperText={heightError?.message ?? rangeText(rules.min_height_m, rules.max_height_m)}
            sx={{ maxWidth: 240 }}
            slotProps={{ htmlInput: { inputMode: 'decimal', className: 'num', autoComplete: 'off' }, input: { endAdornment: <InputAdornment position="end">m</InputAdornment> } }}
          />
          <Box>
            <QuantityStepper
              id={`${id}-pieces`}
              label="Peças"
              value={draft.pieces}
              onChange={set('pieces')}
              onStep={(dir, factor) => set('pieces')(stepValue(draft.pieces, { ...rules, quantity_step: Math.max(1, rules.quantity_step) }, dir, factor, true))}
              min={Math.max(1, minQ)}
              max={maxQ ?? rules.max_pieces}
              valueText={formatPieces(Number(draft.pieces) || 0)}
              integer
              productName={productName}
              error={piecesError?.message}
              onBlur={touch('pieces')}
            />
            {suggestionButtons('pieces', piecesError?.suggestions)}
          </Box>
        </>
      ) : (
        <Box>
          <QuantityStepper
            id={`${id}-qty`}
            label={QUANTITY_FIELD_LABEL[unit]}
            value={draft.quantity}
            onChange={set('quantity')}
            onStep={(dir, factor) => set('quantity')(stepValue(draft.quantity, rules, dir, factor))}
            onBoundary={(edge) => set('quantity')(formatMilli(toMilli(edge === 'min' ? minQ : (maxQ ?? minQ))).replace(/\./g, ''))}
            min={minQ}
            max={maxQ}
            valueText={valid ? formatQuantityMilli(valid.billableMilli, unit) : draft.quantity}
            adornment={INPUT_ADORNMENT[unit]}
            integer={isIntegerUnit(unit)}
            productName={productName}
            error={qtyError?.message}
            helperText={stepHelp()}
            onBlur={touch('quantity')}
          />
          {suggestionButtons('quantity', qtyError?.suggestions)}
          {unit === 'ROLL' && variant.roll_length_m ? (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              Rolo de {formatMetersMilli(toMilli(variant.roll_length_m))}
            </Typography>
          ) : null}
          {unit === 'BOX' && variant.units_per_box ? (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
              Caixa com {new Intl.NumberFormat('pt-BR').format(variant.units_per_box)} un
            </Typography>
          ) : null}
        </Box>
      )}

      {unit === 'SQUARE_METER' ? (
        <Box aria-live="polite">
          <Typography sx={{ display: 'flex', alignItems: 'center', gap: 1, fontWeight: 600 }} className="num">
            <AspectRatioOutlined fontSize="small" aria-hidden /> Área: {areaMilli !== null ? formatAreaMilli(areaMilli) : '—'}
          </Typography>
          {valid ? (
            <Typography variant="body2" color="text.secondary" className="num">
              {labelFor(valid)}
            </Typography>
          ) : null}
          {minAreaApplied && areaMilli !== null && billableMilli !== null && rules.min_billable_area_m2 !== null ? (
            <Alert severity="info" sx={{ mt: 2 }}>
              Área calculada {formatAreaMilli(areaMilli)}. Cobramos a área mínima de {formatAreaMilli(toMilli(rules.min_billable_area_m2))} por peça deste material
              {' '}(faturado: {formatAreaMilli(billableMilli)}).
            </Alert>
          ) : null}
        </Box>
      ) : null}

      <Box
        aria-live="polite"
        aria-busy={recalculating ? 'true' : 'false'}
        sx={{ opacity: recalculating ? 0.6 : 1, transition: 'opacity 150ms', p: 3, bgcolor: 'grey.50', borderRadius: 2 }}
        data-testid="configurator-total"
      >
        {valid && totalCents !== null && billableMilli !== null ? (
          <>
            {showMath() ? (
              <Typography variant="body2" className="num">
                {mathQuantity(billableMilli)} × {formatBRL(unitPrice)}
                {NBSP}
                {UNIT_SUFFIX[unit]} = {formatBRL(totalCents)}
              </Typography>
            ) : null}
            <Typography variant="total" component="p" aria-label={`Total: ${spokenBRL(totalCents)}`}>
              Total: {formatBRL(totalCents)}
            </Typography>
          </>
        ) : (
          <Typography color="text.secondary">Total: —</Typography>
        )}
        {weightGrams !== null && weightGrams > 0 ? (
          <Tooltip title="Peso usado para calcular o frete. Pode variar com a embalagem.">
            <Typography variant="body2" color="text.secondary" sx={{ display: 'inline-flex', alignItems: 'center', gap: 1, mt: 1 }} className="num" tabIndex={0}>
              <ScaleOutlined fontSize="small" aria-hidden /> Peso aprox.: {formatWeight(weightGrams)}
            </Typography>
          </Tooltip>
        ) : null}
        {tierHint()}
      </Box>

      {stockInsufficient && availableMilli !== null ? (
        <Alert severity="warning">Disponível: {formatQuantityMilli(availableMilli, unit === 'SQUARE_METER' ? 'SQUARE_METER' : unit)}. Ajuste a quantidade.</Alert>
      ) : null}
      {serverError && !serverError.isValidation ? (
        <Typography variant="caption" color="text.secondary">
          Não foi possível confirmar o preço agora; o valor exibido é uma estimativa.
        </Typography>
      ) : null}
    </Box>
  );

  function stepHelp(): string {
    const u = unit;
    if (isIntegerUnit(u)) {
      return stepMilli > 1000 ? `Vendido em múltiplos de ${formatMilli(stepMilli)} · mínimo ${formatMilli(toMilli(minQ))}` : `Mínimo ${formatQuantityMilli(toMilli(minQ), u)}`;
    }
    return `Vendido em múltiplos de ${formatMilli(stepMilli, Math.min(2, stepDecimals(stepMilli)))}${NBSP}${INPUT_ADORNMENT[u]} · mínimo ${formatMilli(toMilli(minQ))}${NBSP}${INPUT_ADORNMENT[u]}`;
  }

  function showMath(): boolean {
    return !(isIntegerUnit(unit) && billableMilli === 1000);
  }

  function mathQuantity(milli: number): string {
    if (unit === 'SQUARE_METER') return `${formatAreaMilli(milli)}${minAreaApplied ? ' (mínimo)' : ''}`;
    return formatQuantityMilli(milli, unit);
  }

  function tierHint() {
    const next = preview?.next_tier;
    if (!next || billableMilli === null) return null;
    const missing = toMilli(next.missing_quantity);
    if (missing > toMilli(next.min_quantity) * 0.3) return null;
    return (
      <Typography variant="body2" sx={{ mt: 1, color: 'success.dark' }} className="num">
        Faltam {formatQuantityMilli(missing, unit)} para pagar {formatBRL(next.unit_price_cents)}
        {NBSP}
        {UNIT_SUFFIX[unit]}
      </Typography>
    );
  }
}

function rangeText(min: number | null, max: number | null): string | undefined {
  if (min === null && max === null) return undefined;
  const f = (v: number) => `${formatMilli(toMilli(v), 2, 2)}${NBSP}m`;
  if (min !== null && max !== null) return `Entre ${f(min)} e ${f(max)}`;
  return min !== null ? `Mínimo ${f(min)}` : `Máximo ${f(max!)}`;
}
