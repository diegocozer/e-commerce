import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Grid from '@mui/material/Grid';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import { useAuth } from '@/features/auth';
import { formatDate } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { OrderStatusChip } from '@/shared/ui/OrderStatusChip';
import { PageHeading } from '@/shared/ui/PageHeading';
import { ReorderSuggestions } from '../components/ReorderSuggestions';
import { useAddresses, useOrders } from '../hooks/queries';

export default function AccountOverviewPage() {
  const { customer } = useAuth();
  const last = useOrders(1, null, 1);
  const pending = useOrders(1, ['pending_payment'], 3);
  const addresses = useAddresses();
  const lastOrder = last.data?.data[0];
  const def = addresses.data?.find((a) => a.is_default) ?? addresses.data?.[0];
  return (
    <Box>
      <PageHeading>Visão geral</PageHeading>
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, sm: 6 }}>
          <Card sx={{ p: 4, height: '100%' }}>
            <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Último pedido</Typography>
            {last.isLoading ? <Skeleton height={60} /> : lastOrder ? (
              <>
                <Typography sx={{ fontWeight: 600 }}>{lastOrder.number} · {formatDate(lastOrder.placed_at)}</Typography>
                <OrderStatusChip status={lastOrder.status} label={lastOrder.status_label} />
                <Typography className="num" sx={{ my: 2 }}>{formatBRL(lastOrder.total_cents)}</Typography>
                <Button component={RouterLink} to={`/conta/pedidos/${lastOrder.uuid}`} variant="outlined" size="medium">Ver</Button>
              </>
            ) : (
              <>
                <Typography color="text.secondary" sx={{ mb: 2 }}>Você ainda não fez pedidos.</Typography>
                <Button component={RouterLink} to="/" size="medium">Explorar categorias</Button>
              </>
            )}
          </Card>
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <Card sx={{ p: 4, height: '100%' }}>
            <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Aguardando pagamento</Typography>
            {pending.data?.data.length ? pending.data.data.map((o) => (
              <Box key={o.uuid} sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', py: 1 }}>
                <Typography>{o.number} · {formatBRL(o.total_cents)}</Typography>
                {o.allowed_actions.can_pay || o.allowed_actions.can_retry_payment ? (
                  <Button component={RouterLink} to={`/checkout/pedido/${o.uuid}`} color="secondary" size="medium">Pagar</Button>
                ) : null}
              </Box>
            )) : <Typography color="text.secondary">Nenhum pedido pendente.</Typography>}
          </Card>
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <Card sx={{ p: 4, height: '100%' }}>
            <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Endereço padrão</Typography>
            {def ? <Typography variant="body2">{def.formatted}</Typography> : <Typography color="text.secondary">Nenhum endereço salvo.</Typography>}
            <Button component={RouterLink} to="/conta/enderecos" variant="text" sx={{ mt: 2 }}>{def ? 'Editar' : 'Adicionar endereço'}</Button>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, sm: 6 }}>
          <Card sx={{ p: 4, height: '100%' }}>
            <Typography variant="h4" component="h2" sx={{ mb: 2 }}>Condição comercial</Typography>
            <Typography>
              {customer?.price_list ? <>Você compra com preço <strong>{customer.price_list.name}</strong></> : 'Preço de varejo'}
            </Typography>
          </Card>
        </Grid>
        <Grid size={12}>
          <Card sx={{ p: 4 }}>
            <Typography variant="h4" component="h2" sx={{ mb: 3 }}>Compre de novo</Typography>
            <ReorderSuggestions />
          </Card>
        </Grid>
      </Grid>
    </Box>
  );
}
