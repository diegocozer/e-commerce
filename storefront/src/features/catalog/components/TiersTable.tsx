import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableRow from '@mui/material/TableRow';
import type { PriceTierDisplay, SaleUnit } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatMilli, toMilli } from '@/shared/saleUnit/decimal';
import { NBSP, UNIT_SUFFIX } from '@/shared/saleUnit/labels';

const ABBR: Record<SaleUnit, string> = { UNIT: 'un', LINEAR_METER: 'm', SQUARE_METER: 'm²', ROLL: 'rolos', KG: 'kg', BOX: 'cx' };

function rangeLabel(t: PriceTierDisplay, unit: SaleUnit): string {
  const min = formatMilli(toMilli(t.min_quantity), 0, unit === 'SQUARE_METER' ? 2 : 3);
  if (t.max_quantity === null) return `${min}+${NBSP}${ABBR[unit]}`;
  return `${min}–${formatMilli(toMilli(t.max_quantity))}${NBSP}${ABBR[unit]}`;
}

/** Tabela "Preço por quantidade" com a faixa ativa destacada (UX §4.4.3). */
export function TiersTable({ tiers, unit, activeMin }: { tiers: PriceTierDisplay[]; unit: SaleUnit; activeMin: number | null }) {
  if (tiers.length < 2) return null;
  return (
    <Table size="small" sx={{ border: 1, borderColor: 'divider', borderRadius: 2, borderCollapse: 'separate', maxWidth: 360 }}>
      <caption style={{ captionSide: 'top', textAlign: 'left', padding: '4px 0', fontWeight: 600 }}>Preço por quantidade</caption>
      <TableBody>
        {tiers.map((t) => {
          const active = activeMin !== null && toMilli(t.min_quantity) === toMilli(activeMin);
          return (
            <TableRow key={t.min_quantity} sx={{ bgcolor: active ? 'primary.light' : undefined }}>
              <TableCell component="th" scope="row" className="num">
                {active ? '✓ ' : ''}
                {rangeLabel(t, unit)}
                {active ? <span className="visually-hidden"> (faixa atual)</span> : null}
              </TableCell>
              <TableCell className="num" sx={{ fontWeight: active ? 700 : 400 }}>
                {formatBRL(t.unit_price_cents)}
                {NBSP}
                {UNIT_SUFFIX[unit]}
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
