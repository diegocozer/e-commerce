import Box from '@mui/material/Box';
import LinearProgress from '@mui/material/LinearProgress';
import Typography from '@mui/material/Typography';
import type { Cart } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';

export function FreeShippingProgress({ progress }: { progress: Cart['free_shipping_progress'] }) {
  if (!progress) return null;
  const done = progress.threshold_cents - progress.remaining_cents;
  const pct = Math.min(100, Math.round((done / progress.threshold_cents) * 100));
  return (
    <Box sx={{ my: 2 }}>
      <Typography variant="body2" sx={{ mb: 1 }}>
        {progress.remaining_cents > 0 ? `${progress.text} — faltam ${formatBRL(progress.remaining_cents)}` : 'Você ganhou frete grátis na região!'}
      </Typography>
      <LinearProgress variant="determinate" value={pct} color="success" aria-label="Progresso para frete grátis" />
    </Box>
  );
}
