import RefreshIcon from '@mui/icons-material/RefreshOutlined';
import { Box, Button, Card, CardActionArea, CardContent, Grid, Link, List, ListItem, ListItemText, Skeleton, Stack, Typography } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import type { Dashboard } from '@/shared/api/types';
import { formatTime } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { formatQuantity, formatStock } from '@/shared/formatters/quantity';
import { ErrorState, OrderStatusChip, PageHeader } from '@/shared/ui';
import { useDashboard } from '../api';
import { RevenueChart } from '../components/RevenueChart';
import { StatCard } from '../components/StatCard';

function Queues({ q }: { q: NonNullable<Dashboard['queues']> }) {
  const cards = [
    { label: 'Aguardando pagamento', value: q.pending_payment, to: '/pedidos?status=pending_payment' },
    { label: 'Para separar', value: q.to_pick, to: '/pedidos?status=paid&sort=paid_at', warn: q.to_pick > 0 },
    { label: 'Para enviar', value: q.to_ship, to: '/pedidos?status=processing&shipping_method_type=own_delivery,table_rate,carrier' },
    { label: 'Preparar retirada', value: q.to_prepare_pickup, to: '/pedidos?status=processing&shipping_method_type=pickup' },
    { label: 'Prontos p/ retirada', value: q.ready_for_pickup, to: '/pedidos?status=ready_for_pickup' },
    { label: 'Solicitações de cancelamento', value: q.cancellation_requests, to: '/pedidos?cancellation_requested=1', warn: q.cancellation_requests > 0 },
  ];
  return (
    <Grid container spacing={3}>
      {cards.map((c) => (
        <Grid key={c.label} size={{ xs: 6, md: 4, lg: 2 }}>
          <Card sx={{ height: '100%', borderColor: c.warn ? 'warning.main' : undefined }}>
            <CardActionArea component={RouterLink} to={c.to} sx={{ height: '100%' }}>
              <CardContent>
                <Typography variant="body2" color="text.secondary">
                  {c.label}
                </Typography>
                <Typography variant="h2" component="p" className="num" sx={{ fontFamily: 'Inter, sans-serif' }}>
                  {c.value} {c.warn && <Box component="span" sx={{ fontSize: 14, color: 'warning.main' }}>⚠ atenção</Box>}
                </Typography>
              </CardContent>
            </CardActionArea>
          </Card>
        </Grid>
      ))}
    </Grid>
  );
}

function ListCard({ title, children, action }: { title: string; children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <Card sx={{ height: '100%' }}>
      <CardContent>
        <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center' }}>
          <Typography variant="h3" component="h2">
            {title}
          </Typography>
          {action}
        </Stack>
        {children}
      </CardContent>
    </Card>
  );
}

export default function DashboardPage() {
  const { data, isPending, error, refetch, isFetching, dataUpdatedAt } = useDashboard();
  return (
    <>
      <PageHeader
        title="Dashboard"
        actions={
          <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
            {data && (
              <Typography variant="body2" color="text.secondary">
                Atualizado às {formatTime(new Date(dataUpdatedAt).toISOString())}
              </Typography>
            )}
            <Button startIcon={<RefreshIcon />} onClick={() => void refetch()} disabled={isFetching} aria-label="Atualizar dashboard">
              Atualizar
            </Button>
          </Stack>
        }
      />
      {error && !data && <ErrorState error={error} onRetry={() => void refetch()} />}
      {isPending && (
        <Grid container spacing={3} aria-busy="true">
          {Array.from({ length: 4 }, (_, i) => (
            <Grid key={i} size={{ xs: 12, sm: 6, lg: 3 }}>
              <Skeleton variant="rounded" height={120} />
            </Grid>
          ))}
        </Grid>
      )}
      {data && (
        <Stack spacing={4}>
          {data.sales && (
            <Grid container spacing={3}>
              <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
                <StatCard title="Vendas hoje" value={formatBRL(data.sales.today.revenue_cents.value)} caption={`${data.sales.today.orders_paid.value} pedidos pagos`} kpi={data.sales.today.revenue_cents} compareLabel="de ontem" hint="Vendas = pedidos pagos no período" />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
                <StatCard title="Pedidos pagos no mês" value={String(data.sales.month.orders_paid.value)} kpi={data.sales.month.orders_paid} compareLabel="do mês anterior" hint="Pedidos com pagamento aprovado no mês" />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
                <StatCard title="Faturamento do mês" value={formatBRL(data.sales.month.revenue_cents.value)} kpi={data.sales.month.revenue_cents} compareLabel="do mês anterior" hint="Faturamento = soma de pedidos pagos menos cancelados/estornados" />
              </Grid>
              <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
                <StatCard title="Ticket médio (mês)" value={formatBRL(data.sales.avg_ticket_cents_month.value)} kpi={data.sales.avg_ticket_cents_month} compareLabel="do mês anterior" hint="Ticket médio = faturamento ÷ pedidos pagos" />
              </Grid>
            </Grid>
          )}
          {data.queues && <Queues q={data.queues} />}
          <Grid container spacing={3}>
            {data.sales && (
              <Grid size={{ xs: 12, lg: 8 }}>
                <RevenueChart series={data.sales.revenue_series_30d} />
              </Grid>
            )}
            {data.todays_deliveries && (
              <Grid size={{ xs: 12, lg: 4 }}>
                <ListCard title="Entregas e retiradas do dia">
                  {data.todays_deliveries.length === 0 ? (
                    <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
                      Nenhuma entrega ou retirada prevista para hoje.
                    </Typography>
                  ) : (
                    <List dense>
                      {data.todays_deliveries.map((d) => (
                        <ListItem key={d.order_id} disableGutters secondaryAction={<OrderStatusChip status={d.status} />}>
                          <ListItemText
                            primary={<Link component={RouterLink} to={`/pedidos/${d.order_id}`}>{d.number}</Link>}
                            secondary={`${d.shipping_method_name}${d.district ? ` · ${d.district}` : ''}${d.city ? `, ${d.city}` : ''}`}
                          />
                        </ListItem>
                      ))}
                    </List>
                  )}
                </ListCard>
              </Grid>
            )}
            {data.sales && (
              <Grid size={{ xs: 12, lg: 6 }}>
                <ListCard title="Mais vendidos (30 dias)">
                  <List dense>
                    {data.sales.top_products_30d.map((p, i) => (
                      <ListItem key={p.variant_id} disableGutters secondaryAction={<Typography variant="body2" className="num">{formatBRL(p.revenue_cents)}</Typography>}>
                        <ListItemText primary={`${i + 1}. ${p.name}`} secondary={`${p.sku} · ${formatQuantity(p.quantity, p.sale_unit)}`} />
                      </ListItem>
                    ))}
                  </List>
                </ListCard>
              </Grid>
            )}
            {data.low_stock && (
              <Grid size={{ xs: 12, lg: 6 }}>
                <ListCard
                  title={`Estoque baixo (${data.low_stock.total})`}
                  action={
                    <Button component={RouterLink} to="/estoque?low_stock=1" size="small">
                      Ver todos
                    </Button>
                  }
                >
                  <List dense>
                    {data.low_stock.items.map((i) => (
                      <ListItem key={i.variant_id} disableGutters secondaryAction={<Typography variant="body2" className="num" sx={{ color: 'warning.main', fontWeight: 600 }}>{formatStock(i.available, i.stock_unit_abbr)} ⚠</Typography>}>
                        <ListItemText primary={i.product.name} secondary={`${i.sku} · mínimo ${formatStock(i.low_stock_threshold, i.stock_unit_abbr)}`} />
                      </ListItem>
                    ))}
                  </List>
                </ListCard>
              </Grid>
            )}
          </Grid>
        </Stack>
      )}
    </>
  );
}
