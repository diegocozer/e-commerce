import { Box, Card, CardContent, Chip, Divider, Stack, Typography } from '@mui/material';
import type { AdminOrder, AdminOrderItem } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatConfiguration, formatQuantity, formatWeight } from '@/shared/formatters/quantity';

function ItemRow({ item }: { item: AdminOrderItem }) {
  const isArea = item.sale_unit === 'SQUARE_METER';
  const config = formatConfiguration(item.configuration, item.sale_unit, item.area_m2);
  return (
    <Box sx={{ py: 3 }}>
      <Stack direction={{ xs: 'column', sm: 'row' }} sx={{ justifyContent: 'space-between', gap: 2 }}>
        <div>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {item.product_name}
            {item.variant_name && item.variant_name !== 'Padrão' ? ` · ${item.variant_name}` : ''}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            SKU {item.sku}
          </Typography>
          {isArea && (
            <Typography variant="body2" className="num" data-testid="item-configuration">
              {config}
            </Typography>
          )}
          <Typography variant="body2" className="num" color="text.secondary">
            {formatQuantity(item.billable_quantity, item.sale_unit)} × {formatBRL(item.unit_price_cents)} /{item.sale_unit_abbr} = {formatBRL(item.subtotal_cents)}
            {item.min_area_applied && <Chip size="small" label="Área mínima aplicada" sx={{ ml: 1 }} />}
          </Typography>
          {item.discount_cents > 0 && (
            <Typography variant="caption" color="text.secondary" className="num">
              Desconto (cupom): -{formatBRL(item.discount_cents)}
            </Typography>
          )}
        </div>
        <Stack sx={{ alignItems: { sm: 'flex-end' } }}>
          <Typography variant="body2" className="num" sx={{ fontWeight: 600 }}>
            {formatBRL(item.total_cents)}
          </Typography>
          <Chip size="small" color="primary" variant="outlined" label={item.picking_instruction} sx={{ mt: 1 }} />
        </Stack>
      </Stack>
    </Box>
  );
}

export function OrderItemsCard({ order }: { order: AdminOrder }) {
  const t = order.totals;
  return (
    <Card>
      <CardContent>
        <Typography variant="h3" component="h2">
          Itens ({order.items.length})
        </Typography>
        {order.items.map((i, idx) => (
          <Box key={i.id}>
            {idx > 0 && <Divider />}
            <ItemRow item={i} />
          </Box>
        ))}
        <Divider sx={{ my: 2 }} />
        <Stack spacing={1} className="num">
          <Row label="Subtotal" value={formatBRL(t.subtotal_cents)} />
          {t.discount_cents > 0 && <Row label={`Desconto${order.coupon ? ` (${order.coupon.code})` : ''}`} value={`-${formatBRL(t.discount_cents)}`} />}
          <Row label="Frete" value={formatBRL(t.shipping_cents)} />
          {t.shipping_discount_cents > 0 && <Row label="Desconto no frete" value={`-${formatBRL(t.shipping_discount_cents)}`} />}
          <Row label="Total" value={formatBRL(t.total_cents)} strong />
          <Row label="Peso total" value={formatWeight(order.total_weight_grams)} />
        </Stack>
      </CardContent>
    </Card>
  );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
      <Typography variant="body2" color={strong ? 'text.primary' : 'text.secondary'} sx={{ fontWeight: strong ? 700 : 400 }}>
        {label}
      </Typography>
      <Typography variant="body2" sx={{ fontWeight: strong ? 700 : 400 }}>
        {value}
      </Typography>
    </Stack>
  );
}
