import CheckCircleOutlined from '@mui/icons-material/CheckCircleOutlined';
import ErrorOutlineOutlined from '@mui/icons-material/ErrorOutlineOutlined';
import RemoveCircleOutlineOutlined from '@mui/icons-material/RemoveCircleOutlineOutlined';
import Box from '@mui/material/Box';
import type { AvailabilityStatus, SaleUnit } from '../api/types';
import { formatQuantity } from '../formatters/quantity';

const MAP = {
  in_stock: { label: 'Em estoque', color: 'success.main', Icon: CheckCircleOutlined },
  low_stock: { label: 'Últimas unidades', color: 'warning.main', Icon: ErrorOutlineOutlined },
  out_of_stock: { label: 'Indisponível', color: 'error.main', Icon: RemoveCircleOutlineOutlined },
} as const;

/** RN-CAT-017: ícone + texto (nunca só cor). */
export function StockBadge({
  status,
  availableQuantity,
  unit,
}: {
  status: AvailabilityStatus;
  availableQuantity?: number | null;
  unit?: SaleUnit;
}) {
  const { label, color, Icon } = MAP[status];
  const text =
    status === 'low_stock' && availableQuantity != null && unit ? `Restam ${formatQuantity(availableQuantity, unit)}` : label;
  return (
    <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 1, color, fontWeight: 600, fontSize: 14 }}>
      <Icon fontSize="small" aria-hidden />
      <span>{text}</span>
    </Box>
  );
}
