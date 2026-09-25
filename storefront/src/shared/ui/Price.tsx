import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Typography, { type TypographyProps } from '@mui/material/Typography';
import type { SaleUnit } from '../api/types';
import { formatBRL, spokenBRL } from '../formatters/money';
import { NBSP, UNIT_SPOKEN, UNIT_SUFFIX, UNIT_WORD } from '../saleUnit/labels';

interface PriceProps {
  cents: number;
  unit: SaleUnit;
  compareAtCents?: number | null;
  sourceLabel?: string | null;
  /** "/ metro" por extenso (página de produto) em vez de "/m". */
  longUnit?: boolean;
  variant?: TypographyProps['variant'];
  fromPrice?: boolean;
}

/** Preço sempre com unidade (UX §1.1 regra de ouro). */
export function Price({ cents, unit, compareAtCents, sourceLabel, longUnit, variant = 'body1', fromPrice }: PriceProps) {
  const suffix = longUnit ? `${NBSP}/${NBSP}${UNIT_WORD[unit]}` : `${NBSP}${UNIT_SUFFIX[unit]}`;
  const label = `${fromPrice ? 'a partir de ' : ''}${spokenBRL(cents)} ${UNIT_SPOKEN[unit]}`;
  return (
    <Box component="span" sx={{ display: 'inline-flex', flexWrap: 'wrap', alignItems: 'baseline', gap: 2 }}>
      {compareAtCents && compareAtCents > cents ? (
        <Typography component="s" variant="body2" color="text.secondary" className="num" aria-label={`de ${spokenBRL(compareAtCents)}`}>
          {formatBRL(compareAtCents)}
        </Typography>
      ) : null}
      <Typography component="span" variant={variant} className="num" sx={{ fontWeight: 700 }} aria-label={label}>
        {fromPrice ? (
          <Typography component="span" variant="caption" color="text.secondary" aria-hidden>
            a partir de{' '}
          </Typography>
        ) : null}
        <span aria-hidden>{formatBRL(cents)}</span>
        <Typography component="span" variant="body2" color="text.secondary" aria-hidden>
          {suffix}
        </Typography>
      </Typography>
      {sourceLabel ? (
        <Chip size="small" label={sourceLabel} sx={{ bgcolor: 'secondary.light', color: 'secondary.dark' }} />
      ) : null}
    </Box>
  );
}
