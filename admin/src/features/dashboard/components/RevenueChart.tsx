import { Button, Card, CardContent, Stack, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { Dashboard } from '@/shared/api/types';
import { formatDate } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';

type Point = NonNullable<Dashboard['sales']>['revenue_series_30d'][number];

const shortBRL = (cents: number) =>
  new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', notation: 'compact', maximumFractionDigits: 1 }).format(cents / 100);

function renderTooltip({ active, payload }: { active?: boolean; payload?: readonly { payload?: unknown }[] }) {
  const p = active && payload?.[0] ? (payload[0].payload as Point) : null;
  if (!p) return null;
  return (
    <Card sx={{ p: 2 }}>
      <Typography variant="caption">{formatDate(p.date).slice(0, 5)}</Typography>
      <Typography variant="body2" className="num" sx={{ fontWeight: 600 }}>
        {formatBRL(p.revenue_cents)}
      </Typography>
      <Typography variant="caption" className="num">
        {p.orders_paid} {p.orders_paid === 1 ? 'pedido' : 'pedidos'}
      </Typography>
    </Card>
  );
}

/** Colunas diárias, uma cor, eixo Y em R$, tooltip dd/mm + valor + pedidos; alternativa em tabela. */
export function RevenueChart({ series }: { series: Point[] }) {
  const theme = useTheme();
  const [asTable, setAsTable] = useState(false);
  return (
    <Card sx={{ height: '100%' }}>
      <CardContent>
        <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
          <Typography variant="h3" component="h2" id="revenue-title">
            Faturamento — últimos 30 dias
          </Typography>
          <Button size="small" onClick={() => setAsTable((v) => !v)}>
            {asTable ? 'Ver gráfico' : 'Ver dados em tabela'}
          </Button>
        </Stack>
        {asTable ? (
          <Table size="small" aria-labelledby="revenue-title">
            <TableHead>
              <TableRow>
                <TableCell>Data</TableCell>
                <TableCell align="right">Faturamento</TableCell>
                <TableCell align="right">Pedidos pagos</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {series.map((p) => (
                <TableRow key={p.date}>
                  <TableCell>{formatDate(p.date)}</TableCell>
                  <TableCell align="right" className="num">{formatBRL(p.revenue_cents)}</TableCell>
                  <TableCell align="right" className="num">{p.orders_paid}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        ) : (
          <div role="img" aria-label={`Gráfico de colunas do faturamento diário. Total no período: ${formatBRL(series.reduce((s, p) => s + p.revenue_cents, 0))}.`} style={{ width: '100%', height: 260 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={series} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid vertical={false} stroke={theme.palette.divider} />
                <XAxis dataKey="date" tickFormatter={(d: string) => formatDate(d).slice(0, 5)} tick={{ fontSize: 11, fill: theme.palette.text.secondary }} interval="preserveStartEnd" minTickGap={16} />
                <YAxis tickFormatter={shortBRL} tick={{ fontSize: 11, fill: theme.palette.text.secondary }} width={72} />
                <Tooltip cursor={{ fill: theme.palette.action.hover }} content={renderTooltip} />
                <Bar dataKey="revenue_cents" fill={theme.palette.primary.main} radius={[3, 3, 0, 0]} isAnimationActive={false} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
