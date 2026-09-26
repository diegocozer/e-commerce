import DeleteOutlineOutlined from '@mui/icons-material/DeleteOutlineOutlined';
import EditOutlined from '@mui/icons-material/EditOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useEffect, useState } from 'react';
import { Link as RouterLink } from 'react-router';
import { describeError, toApiError } from '@/shared/api/errors';
import type { CartItem, StockIssue } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatArea, formatQuantity } from '@/shared/formatters/quantity';
import { parseDecimal, formatMilli, milliToNumber, toMilli } from '@/shared/saleUnit/decimal';
import { INPUT_ADORNMENT, NBSP, QUANTITY_FIELD_LABEL, UNIT_SUFFIX, isIntegerUnit } from '@/shared/saleUnit/labels';
import { stepValue } from '@/shared/saleUnit/configuration';
import { checkStep } from '@/shared/saleUnit/math';
import { Price } from '@/shared/ui/Price';
import { ProductImg } from '@/shared/ui/ProductImg';
import { QuantityStepper } from '@/shared/ui/QuantityStepper';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { StockBadge } from '@/shared/ui/StockBadge';
import { useAddToCart, useCartMutations } from '../hooks/useCart';
import { EditDimensionsDialog } from './EditDimensionsDialog';

function lineBody(item: CartItem) {
  const c = item.configuration;
  if (item.sale_unit === 'SQUARE_METER') {
    return { variant_id: item.variant_id, ...(item.rules?.fixed_width_m == null && c.width_m !== null ? { width_m: c.width_m } : {}), height_m: c.height_m ?? 0, pieces: c.pieces ?? 1 };
  }
  return { variant_id: item.variant_id, quantity: c.quantity ?? 0 };
}

/** Linha do carrinho (UX §4.5): quantidade digitada fica, totais esperam a API (não otimista). */
export function CartItemRow({ item, highlight }: { item: CartItem; highlight?: boolean }) {
  const { update, remove } = useCartMutations();
  const add = useAddToCart();
  const notify = useSnackbar();
  const m2 = item.sale_unit === 'SQUARE_METER';
  const current = m2 ? String(item.configuration.pieces ?? 1) : formatMilli(toMilli(item.configuration.quantity ?? 0)).replace(/\./g, '');
  const [draft, setDraft] = useState(current);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  // Valor do servidor mudou → sincroniza o rascunho (padrão "estado derivado" do React).
  const [prevCurrent, setPrevCurrent] = useState(current);
  if (prevCurrent !== current) {
    setPrevCurrent(current);
    setDraft(current);
  }

  // Debounce 500 ms (UX §4.5)
  useEffect(() => {
    if (draft === current || !item.rules || update.isPending) return;
    const id = setTimeout(() => {
      const parsed = parseDecimal(draft, m2 ? 0 : 3);
      if (!parsed.ok || parsed.milli <= 0) {
        setError('Quantidade inválida.');
        return;
      }
      const rules = item.rules!;
      const check = checkStep(parsed.milli, toMilli(rules.min_quantity), rules.max_quantity === null ? null : toMilli(rules.max_quantity), toMilli(rules.quantity_step));
      if (!check.ok) {
        setError(check.reason === 'step' ? `Use múltiplos de ${formatMilli(toMilli(rules.quantity_step))}${NBSP}${INPUT_ADORNMENT[item.sale_unit]}.` : check.reason === 'below_min' ? `Mínimo: ${formatMilli(toMilli(rules.min_quantity))}.` : `Máximo: ${formatMilli(toMilli(rules.max_quantity ?? 0))}.`);
        return;
      }
      setError(null);
      const body = m2 ? { pieces: parsed.milli / 1000 } : { quantity: milliToNumber(parsed.milli) };
      update.mutate(
        { id: item.id, body },
        {
          onError: (err) => {
            const e = toApiError(err);
            if (e.code === 'insufficient_stock') {
              const issue = e.extra<StockIssue[]>('items')?.[0];
              setError(issue ? `Disponível: ${formatQuantity(issue.available_quantity, item.sale_unit)}.` : e.message);
            } else setError(e.fieldMessage('quantity') ?? e.fieldMessage('pieces') ?? describeError(e));
            setDraft(current);
          },
        },
      );
    }, 500);
    return () => clearTimeout(id);
  }, [draft, current, item.id, item.rules, item.sale_unit, m2, update]);

  const onRemove = () => {
    remove.mutate(item.id, {
      onSuccess: () =>
        notify('Item removido', {
          severity: 'info',
          actionLabel: 'Desfazer',
          duration: 5000,
          onAction: () => add.mutate(lineBody(item), { onError: (err) => notify(describeError(err), { severity: 'error' }) }),
        }),
      onError: (err) => notify(describeError(err), { severity: 'error' }),
    });
  };

  const blocked = item.status !== 'ok';
  const unavailable = item.status === 'unavailable';
  const updating = update.isPending;
  const attrs = Object.values(item.variant.attributes).join(' · ') || item.variant.name;
  const summary = m2 && item.area_m2 !== null ? `${item.configuration_label} = ${formatArea(item.area_m2)}` : item.configuration_label;

  return (
    <Box
      component="article"
      aria-labelledby={`item-${item.id}-title`}
      data-blocked={blocked ? 'true' : undefined}
      tabIndex={blocked ? -1 : undefined}
      sx={{ display: 'flex', gap: 3, p: 3, borderBottom: 1, borderColor: 'divider', bgcolor: highlight ? 'primary.light' : undefined, opacity: unavailable ? 0.7 : 1 }}
    >
      <Box sx={{ width: { xs: 72, sm: 96 }, flexShrink: 0 }}>
        <ProductImg image={item.product.image} alt={item.product.name} size={96} />
      </Box>
      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 1 }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
          <Typography variant="h4" component="h3" id={`item-${item.id}-title`} sx={{ fontSize: 16 }}>
            <Link component={RouterLink} to={`${item.product.url_path}?sku=${encodeURIComponent(item.variant.sku)}`} color="inherit">
              {item.product.name}
            </Link>
          </Typography>
          <Typography className="num" sx={{ fontWeight: 700, whiteSpace: 'nowrap' }} aria-live="polite">
            {updating ? <Skeleton width={72} /> : item.line_total_cents !== null ? formatBRL(item.line_total_cents) : '—'}
          </Typography>
        </Box>
        <Typography variant="body2" color="text.secondary">
          {attrs} · SKU {item.variant.sku}
        </Typography>
        <Typography variant="body2" className="num">
          {summary}
          {item.min_area_applied && item.billable_quantity !== null ? ` (área mínima aplicada: ${formatArea(item.billable_quantity)} faturados)` : ''}
        </Typography>
        {item.unit_price_cents !== null ? <Price cents={item.unit_price_cents} unit={item.sale_unit} compareAtCents={item.compare_at_cents} sourceLabel={item.price_source_label} variant="body2" /> : null}
        {item.availability.status !== 'in_stock' ? <StockBadge status={item.availability.status} availableQuantity={item.availability.available_quantity} unit={item.sale_unit} /> : null}

        {item.warnings.map((w, i) => {
          switch (w.code) {
            case 'price_changed':
              return (
                <Alert key={i} severity="warning" sx={{ py: 0 }}>
                  Preço atualizado: de <s>{formatBRL(w.previous_unit_price_cents)}</s> para {formatBRL(w.current_unit_price_cents)}
                  {NBSP}
                  {UNIT_SUFFIX[item.sale_unit]}
                </Alert>
              );
            case 'insufficient_stock':
              return (
                <Alert
                  key={i}
                  severity="error"
                  sx={{ py: 0 }}
                  action={
                    !m2 && item.rules ? (
                      <Button color="inherit" size="small" onClick={() => setDraft(formatMilli(toMilli(w.available_quantity)).replace(/\./g, ''))}>
                        Ajustar para {formatQuantity(w.available_quantity, item.sale_unit)}
                      </Button>
                    ) : undefined
                  }
                >
                  Disponível: {formatQuantity(w.available_quantity, item.sale_unit)}. Ajuste a quantidade.
                </Alert>
              );
            case 'unavailable':
              return (
                <Alert key={i} severity="error" sx={{ py: 0 }}>
                  Indisponível — {w.message}
                </Alert>
              );
            case 'invalid_quantity':
              return (
                <Alert key={i} severity="error" sx={{ py: 0 }}>
                  {m2 ? 'As medidas deste item precisam ser revistas.' : w.message}
                  {!m2 && w.suggestions.length ? (
                    <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                      {w.suggestions.map((s) => (
                        <Button key={s} size="small" variant="outlined" color="inherit" onClick={() => setDraft(formatMilli(toMilli(s)).replace(/\./g, ''))}>
                          Usar {formatQuantity(s, item.sale_unit)}
                        </Button>
                      ))}
                    </Stack>
                  ) : null}
                </Alert>
              );
            case 'min_area_applied':
              return null;
            default:
              return null;
          }
        })}

        <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 2, mt: 1 }}>
          {item.rules && !unavailable ? (
            <QuantityStepper
              id={`qty-${item.id}`}
              label={m2 ? 'Peças' : QUANTITY_FIELD_LABEL[item.sale_unit]}
              value={draft}
              size="small"
              onChange={setDraft}
              onStep={(dir, factor) => setDraft(stepValue(draft, m2 ? { ...item.rules!, quantity_step: Math.max(1, item.rules!.quantity_step) } : item.rules!, dir, factor, m2))}
              min={item.rules.min_quantity}
              max={m2 ? (item.rules.max_quantity ?? item.rules.max_pieces) : item.rules.max_quantity}
              valueText={m2 ? `${draft} peças` : `${draft} ${INPUT_ADORNMENT[item.sale_unit]}`}
              adornment={m2 ? undefined : INPUT_ADORNMENT[item.sale_unit]}
              integer={m2 || isIntegerUnit(item.sale_unit)}
              productName={item.product.name}
              error={error}
            />
          ) : null}
          {m2 && item.rules && !unavailable ? (
            <Button size="small" variant="outlined" startIcon={<EditOutlined />} onClick={() => setEditing(true)}>
              Editar medidas
            </Button>
          ) : null}
          <Button size="small" variant="text" color="error" startIcon={<DeleteOutlineOutlined />} onClick={onRemove} loading={remove.isPending} aria-label={`Remover ${item.product.name}`}>
            {unavailable ? 'Remover do carrinho' : 'Remover'}
          </Button>
        </Box>
      </Box>
      {editing && item.rules ? <EditDimensionsDialog item={item} onClose={() => setEditing(false)} /> : null}
    </Box>
  );
}
