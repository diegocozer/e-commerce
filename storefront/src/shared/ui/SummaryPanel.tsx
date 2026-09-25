import Box from '@mui/material/Box';
import Divider from '@mui/material/Divider';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';
import { formatBRL } from '../formatters/money';
import { formatWeight } from '../formatters/quantity';

interface Props {
  subtotalCents: number;
  itemsCount?: number;
  discountCents?: number;
  couponCode?: string | null;
  shippingCents: number | null;
  shippingDiscountCents?: number | null;
  shippingLabel?: string;
  totalCents: number;
  weightGrams: number;
  busy?: boolean;
  children?: ReactNode;
}

function Row({ label, value, strong }: { label: ReactNode; value: ReactNode; strong?: boolean }) {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 4, py: 1 }}>
      <Typography variant={strong ? 'h4' : 'body2'} component="dt">
        {label}
      </Typography>
      <Typography variant={strong ? 'total' : 'body2'} component="dd" className="num" sx={{ m: 0, textAlign: 'right' }}>
        {value}
      </Typography>
    </Box>
  );
}

/** Resumo de valores (UX §4.0) — sempre valores do servidor. */
export function SummaryPanel(p: Props) {
  return (
    <Box aria-live="polite" aria-busy={p.busy ? 'true' : 'false'} sx={{ opacity: p.busy ? 0.6 : 1, transition: 'opacity 150ms' }}>
      <Box component="dl" sx={{ m: 0 }}>
        <Row label={`Subtotal${p.itemsCount != null ? ` (${p.itemsCount} ${p.itemsCount === 1 ? 'item' : 'itens'})` : ''}`} value={formatBRL(p.subtotalCents)} />
        {p.discountCents ? <Row label={`Desconto${p.couponCode ? ` (${p.couponCode})` : ''}`} value={`-${formatBRL(p.discountCents)}`} /> : null}
        <Row
          label={p.shippingLabel ?? 'Frete'}
          value={p.shippingCents === null ? 'a calcular' : p.shippingCents === 0 ? <Box component="span" sx={{ color: 'success.main', fontWeight: 600 }}>Grátis</Box> : formatBRL(p.shippingCents)}
        />
        {p.shippingDiscountCents ? <Row label="Desconto no frete" value={`-${formatBRL(p.shippingDiscountCents)}`} /> : null}
        <Row label="Peso total aprox." value={formatWeight(p.weightGrams)} />
        <Divider sx={{ my: 2 }} />
        <Row label="Total" value={formatBRL(p.totalCents)} strong />
      </Box>
      {p.children}
    </Box>
  );
}
