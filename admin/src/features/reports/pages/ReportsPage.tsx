import DownloadIcon from '@mui/icons-material/DownloadOutlined';
import { Alert, Box, Button, Card, CardContent, Grid, MenuItem, Stack, Tab, Table, TableBody, TableCell, TableContainer, TableFooter, TableHead, TableRow, Tabs, TextField, Typography } from '@mui/material';
import { useTheme } from '@mui/material/styles';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { errorMessage } from '@/shared/api/errors';
import { flattenCategories, useBrandOptions, useCategoryTree, useShippingMethods } from '@/shared/api/lookups';
import { useCan, useCanFn } from '@/shared/auth';
import { addDaysISO, endOfPrevMonthISO, formatDate, startOfMonthISO, todayISO } from '@/shared/formatters/date';
import { ORDER_STATUS } from '@/shared/formatters/labels';
import { formatBRL } from '@/shared/formatters/money';
import { useListParams } from '@/shared/hooks/useListParams';
import { ErrorState, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { exportReportCsv, useReport, type AnyRow } from '../api';
import { REPORTS, type ReportDef } from '../components/definitions';

function presets(): { label: string; from: string; to: string }[] {
  const t = todayISO();
  return [
    { label: 'Hoje', from: t, to: t },
    { label: 'Ontem', from: addDaysISO(t, -1), to: addDaysISO(t, -1) },
    { label: '7 dias', from: addDaysISO(t, -6), to: t },
    { label: '30 dias', from: addDaysISO(t, -29), to: t },
    { label: 'Este mês', from: startOfMonthISO(t), to: t },
    { label: 'Mês anterior', from: startOfMonthISO(endOfPrevMonthISO(t)), to: endOfPrevMonthISO(t) },
  ];
}

function ReportChart({ def, rows }: { def: ReportDef; rows: AnyRow[] }) {
  const theme = useTheme();
  const [table, setTable] = useState(false);
  if (!def.chart || rows.length === 0) return null;
  const { x, y, label } = def.chart;
  return (
    <Card>
      <CardContent>
        <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
          <Typography variant="h3" component="h2">{label} por período</Typography>
          <Button size="small" onClick={() => setTable(!table)}>{table ? 'Ver gráfico' : 'Ver como tabela'}</Button>
        </Stack>
        {table ? (
          <Table size="small">
            <TableBody>
              {rows.map((r) => (
                <TableRow key={String(r[x])}><TableCell>{formatDate(String(r[x]))}</TableCell><TableCell align="right" className="num">{formatBRL(Number(r[y]))}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        ) : (
          <Box role="img" aria-label={`Gráfico de colunas: ${label} por período`} sx={{ height: 240 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={rows}>
                <CartesianGrid vertical={false} stroke={theme.palette.divider} />
                <XAxis dataKey={x} tickFormatter={(d: string) => formatDate(d).slice(0, 5)} tick={{ fontSize: 11, fill: theme.palette.text.secondary }} />
                <YAxis tickFormatter={(c: number) => new Intl.NumberFormat('pt-BR', { notation: 'compact', style: 'currency', currency: 'BRL' }).format(c / 100)} tick={{ fontSize: 11, fill: theme.palette.text.secondary }} width={72} />
                <Tooltip formatter={(v) => formatBRL(Number(v))} labelFormatter={(d) => formatDate(String(d))} />
                <Bar dataKey={y} name={label} fill={theme.palette.primary.main} isAnimationActive={false} />
              </BarChart>
            </ResponsiveContainer>
          </Box>
        )}
      </CardContent>
    </Card>
  );
}

export default function ReportsPage() {
  const can = useCanFn();
  const canExport = useCan('reports.export');
  const available = REPORTS.filter((r) => can(r.perms));
  const { params, update } = useListParams();
  const current = available.find((r) => r.name === params.filters.report) ?? available[0];
  const t = todayISO();
  const from = params.filters.date_from ?? addDaysISO(t, -29);
  const to = params.filters.date_to ?? t;
  const cats = flattenCategories(useCategoryTree(!!current?.filters?.includes('category')).data);
  const brands = useBrandOptions(!!current?.filters?.includes('brand')).data ?? [];
  const methods = useShippingMethods(!!current?.filters?.includes('shipping_method') && can('shipping.manage')).data ?? [];
  const query = {
    date_from: from,
    date_to: to,
    group_by: current?.groupBy ? (params.filters.group_by ?? 'day') : undefined,
    category_id: params.filters.category_id,
    brand_id: params.filters.brand_id,
    shipping_method_id: params.filters.shipping_method_id,
    status: params.filters.status,
  };
  const rangeError = from > to ? 'A data inicial deve ser anterior à final.' : addDaysISO(from, 366) < to ? 'Período máximo de 366 dias.' : null;
  const q = useReport(current?.name ?? 'sales', query, !!current && !rangeError);
  const [exporting, setExporting] = useState(false);

  if (!current) return <ErrorState title="Sem acesso a relatórios." error={null} />;
  const data = q.data;
  const totals = data?.totals;

  return (
    <>
      <PageHeader
        title="Relatórios"
        actions={
          canExport && (
            <Button
              startIcon={<DownloadIcon />}
              disabled={exporting || !!rangeError}
              onClick={() => {
                setExporting(true);
                exportReportCsv(current.name, query)
                  .then((f) => notify.success(`Exportado: ${f}`))
                  .catch((e) => notify.error(errorMessage(e)))
                  .finally(() => setExporting(false));
              }}
            >
              Exportar CSV
            </Button>
          )
        }
      />
      <Tabs value={current.name} onChange={(_, v: string) => update({ report: v })} variant="scrollable" allowScrollButtonsMobile sx={{ mb: 3, borderBottom: 1, borderColor: 'divider' }}>
        {available.map((r) => <Tab key={r.name} value={r.name} label={r.label} />)}
      </Tabs>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 3, flexWrap: 'wrap', rowGap: 2, alignItems: { md: 'center' } }}>
        <TextField select label="Período" value="" onChange={(e) => { const p = presets().find((x) => x.label === e.target.value); if (p) update({ date_from: p.from, date_to: p.to }); }} sx={{ width: { md: 160 } }}>
          {presets().map((p) => <MenuItem key={p.label} value={p.label}>{p.label}</MenuItem>)}
        </TextField>
        <TextField type="date" label="De" value={from} onChange={(e) => update({ date_from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
        <TextField type="date" label="Até" value={to} onChange={(e) => update({ date_to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} sx={{ width: { md: 160 } }} />
        {current.groupBy && (
          <TextField select label="Agrupar por" value={params.filters.group_by ?? 'day'} onChange={(e) => update({ group_by: e.target.value })} sx={{ width: { md: 140 } }}>
            <MenuItem value="day">Dia</MenuItem><MenuItem value="week">Semana</MenuItem><MenuItem value="month">Mês</MenuItem>
          </TextField>
        )}
        {current.filters?.includes('category') && (
          <TextField select label="Categoria" value={params.filters.category_id ?? ''} onChange={(e) => update({ category_id: e.target.value })} sx={{ width: { md: 200 } }}>
            <MenuItem value="">Todas</MenuItem>{cats.map((c) => <MenuItem key={c.id} value={String(c.id)}>{c.label}</MenuItem>)}
          </TextField>
        )}
        {current.filters?.includes('brand') && (
          <TextField select label="Marca" value={params.filters.brand_id ?? ''} onChange={(e) => update({ brand_id: e.target.value })} sx={{ width: { md: 160 } }}>
            <MenuItem value="">Todas</MenuItem>{brands.map((b) => <MenuItem key={b.id} value={String(b.id)}>{b.name}</MenuItem>)}
          </TextField>
        )}
        {current.filters?.includes('shipping_method') && methods.length > 0 && (
          <TextField select label="Método" value={params.filters.shipping_method_id ?? ''} onChange={(e) => update({ shipping_method_id: e.target.value })} sx={{ width: { md: 180 } }}>
            <MenuItem value="">Todos</MenuItem>{methods.map((m) => <MenuItem key={m.id} value={String(m.id)}>{m.name}</MenuItem>)}
          </TextField>
        )}
        {current.filters?.includes('status') && (
          <TextField select label="Status" value={params.filters.status ?? ''} onChange={(e) => update({ status: e.target.value })} sx={{ width: { md: 180 } }}>
            <MenuItem value="">Todos</MenuItem>{Object.entries(ORDER_STATUS).map(([v, s]) => <MenuItem key={v} value={v}>{s.label}</MenuItem>)}
          </TextField>
        )}
      </Stack>
      {rangeError && <Alert severity="error" sx={{ mb: 3 }}>{rangeError}</Alert>}
      {q.isPending && !rangeError && <LoadingBlock lines={6} />}
      {q.error && <ErrorState error={q.error} onRetry={() => void q.refetch()} />}
      {data && (
        <Stack spacing={4} aria-busy={q.isFetching}>
          <Grid container spacing={3}>
            {current.summary.map((s) => (
              <Grid key={s.key} size={{ xs: 6, md: 3, lg: 2 }}>
                <Card sx={{ height: '100%' }}>
                  <CardContent>
                    <Typography variant="body2" color="text.secondary">{s.label}</Typography>
                    <Typography variant="h3" component="p" className="num" sx={{ fontFamily: 'Inter, sans-serif' }}>{s.fmt(data.summary[s.key] ?? null, {})}</Typography>
                  </CardContent>
                </Card>
              </Grid>
            ))}
          </Grid>
          {current.name === 'margin' && data.summary.cost_coverage_bp !== null && Number(data.summary.cost_coverage_bp) < 10000 && (
            <Alert severity="warning">Cadastre o custo das variantes: parte da receita não tem custo informado.</Alert>
          )}
          <ReportChart def={current} rows={data.rows} />
          <Card>
            <TableContainer>
              <Table size="small" aria-label={current.label}>
                <TableHead>
                  <TableRow>{current.columns.map((c) => <TableCell key={c.key} align={c.align}>{c.header}</TableCell>)}</TableRow>
                </TableHead>
                <TableBody>
                  {data.rows.map((r, i) => (
                    <TableRow key={i}>{current.columns.map((c) => <TableCell key={c.key} align={c.align} className={c.align ? 'num' : undefined}>{c.fmt(r[c.key] ?? null, r)}</TableCell>)}</TableRow>
                  ))}
                  {data.rows.length === 0 && <TableRow><TableCell colSpan={current.columns.length}>Sem dados no período.</TableCell></TableRow>}
                </TableBody>
                {totals && (
                  <TableFooter>
                    <TableRow>
                      {current.columns.map((c, i) => (
                        <TableCell key={c.key} align={c.align} className="num" sx={{ fontWeight: 700 }}>
                          {i === 0 ? 'Total' : totals[c.key] !== undefined ? c.fmt((totals as AnyRow)[c.key] ?? null, totals as AnyRow) : ''}
                        </TableCell>
                      ))}
                    </TableRow>
                  </TableFooter>
                )}
              </Table>
            </TableContainer>
          </Card>
          {current.emptyHint && <Typography variant="caption" color="text.secondary">{current.emptyHint}</Typography>}
        </Stack>
      )}
    </>
  );
}
