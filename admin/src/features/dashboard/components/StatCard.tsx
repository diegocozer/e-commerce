import InfoIcon from '@mui/icons-material/InfoOutlined';
import { Card, CardContent, Stack, Tooltip, Typography } from '@mui/material';
import type { KpiValue } from '@/shared/api/types';
import { formatBp } from '@/shared/formatters/money';

/** KPI com variação ▲/▼ **e** texto (cor não é o único sinal — UX §5.3). */
export function StatCard({ title, value, kpi, caption, hint, compareLabel }: { title: string; value: string; kpi?: KpiValue; caption?: string; hint?: string; compareLabel: string }) {
  const change = kpi?.change_bp ?? null;
  const up = change !== null && change > 0;
  const down = change !== null && change < 0;
  return (
    <Card sx={{ height: '100%' }}>
      <CardContent>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
          <Typography variant="body2" color="text.secondary">
            {title}
          </Typography>
          {hint && (
            <Tooltip title={hint}>
              <InfoIcon sx={{ fontSize: 16, color: 'text.secondary' }} aria-label={hint} />
            </Tooltip>
          )}
        </Stack>
        <Typography variant="h2" component="p" className="num" sx={{ mt: 1, fontFamily: 'Inter, sans-serif' }}>
          {value}
        </Typography>
        {caption && (
          <Typography variant="body2" color="text.secondary" className="num">
            {caption}
          </Typography>
        )}
        {change !== null && (
          <Typography variant="caption" className="num" sx={{ color: up ? 'success.main' : down ? 'error.main' : 'text.secondary', fontWeight: 600 }}>
            {up ? '▲' : down ? '▼' : '•'} {formatBp(Math.abs(change))} {up ? 'acima' : down ? 'abaixo' : 'igual'} {compareLabel}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
}
