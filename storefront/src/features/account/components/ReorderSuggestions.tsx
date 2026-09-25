import ReplayOutlined from '@mui/icons-material/ReplayOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router';
import { useAddToCartFlow } from '@/features/cart/hooks/useAddToCartFlow';
import type { ReorderSuggestion } from '@/shared/api/types';
import { Price } from '@/shared/ui/Price';
import { ProductImg } from '@/shared/ui/ProductImg';
import { useReorderSuggestions } from '../hooks/queries';

function lineOf(s: ReorderSuggestion) {
  const c = s.last_configuration;
  if (s.product.sale_unit === 'SQUARE_METER') {
    return { variant_id: s.variant_id, ...(c.width_m !== null ? { width_m: c.width_m } : {}), height_m: c.height_m ?? 0, pieces: c.pieces ?? 1 };
  }
  return { variant_id: s.variant_id, quantity: c.quantity ?? 1 };
}

/** "Compre de novo": adiciona a mesma configuração do último pedido e abre o mini-carrinho. */
export function ReorderSuggestions({ limit = 6 }: { limit?: number }) {
  const q = useReorderSuggestions();
  const { addToCart } = useAddToCartFlow();
  const [pending, setPending] = useState<number | null>(null);
  if (!q.data) return null;
  if (!q.data.length) {
    return (
      <Typography color="text.secondary" variant="body2">
        Seus produtos comprados aparecerão aqui para recomprar em 1 clique.
      </Typography>
    );
  }
  return (
    <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, display: 'flex', flexDirection: 'column', gap: 3 }}>
      {q.data.slice(0, limit).map((s) => (
        <Box component="li" key={s.variant_id} sx={{ display: 'flex', gap: 3, alignItems: 'center' }}>
          <ProductImg image={s.product.image} alt={s.product.name} size={56} />
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Link component={RouterLink} to={s.product.url_path} sx={{ fontWeight: 600 }}>
              {s.product.name}
            </Link>
            <Typography variant="caption" color="text.secondary" component="p">
              {s.variant.name} · último: {s.last_configuration_label}
            </Typography>
            <Price cents={s.current_unit_price_cents} unit={s.product.sale_unit} variant="body2" />
          </Box>
          <Button
            size="small"
            variant="outlined"
            color="secondary"
            startIcon={<ReplayOutlined />}
            disabled={s.availability.status === 'out_of_stock'}
            loading={pending === s.variant_id}
            aria-label={`Adicionar ${s.product.name} (${s.last_configuration_label}) ao carrinho`}
            onClick={async () => {
              setPending(s.variant_id);
              await addToCart(lineOf(s), { productName: s.product.name, configurationLabel: s.last_configuration_label, unit: s.product.sale_unit });
              setPending(null);
            }}
          >
            Carrinho
          </Button>
        </Box>
      ))}
    </Box>
  );
}
