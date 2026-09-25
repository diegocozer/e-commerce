import Close from '@mui/icons-material/Close';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import { formatBRL } from '@/shared/formatters/money';
import { ProductImg } from '@/shared/ui/ProductImg';
import { useCart } from '../hooks/useCart';
import { useMiniCart } from '../hooks/useMiniCart';
import { FreeShippingProgress } from './FreeShippingProgress';

/** Mini-carrinho (Drawer direito 400 px, desktop — UX §4.4.7). */
export function MiniCartDrawer() {
  const { open, close, highlightId } = useMiniCart();
  const cart = useCart();
  const c = cart.data;
  return (
    <Drawer anchor="right" open={open} onClose={close} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 400 }, p: 4 }, 'aria-labelledby': 'mini-cart-title' } as object }}>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
        <Typography variant="h3" component="h2" id="mini-cart-title">
          Seu carrinho
        </Typography>
        <IconButton aria-label="Fechar carrinho" onClick={close}>
          <Close />
        </IconButton>
      </Box>
      {c && c.items.length ? (
        <>
          <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, flex: 1, overflowY: 'auto' }}>
            {c.items.map((i) => (
              <Box component="li" key={i.id} sx={{ display: 'flex', gap: 3, py: 2, px: 2, borderRadius: 2, bgcolor: i.id === highlightId ? 'primary.light' : undefined }}>
                <ProductImg image={i.product.image} alt={i.product.name} size={56} />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography sx={{ fontWeight: 600, fontSize: 14 }}>{i.product.name}</Typography>
                  <Typography variant="caption" color="text.secondary" component="p">
                    {i.variant.name} · {i.configuration_label}
                  </Typography>
                </Box>
                <Typography className="num" sx={{ fontWeight: 600, fontSize: 14 }}>
                  {i.line_total_cents !== null ? formatBRL(i.line_total_cents) : '—'}
                </Typography>
              </Box>
            ))}
          </Box>
          <Divider sx={{ my: 3 }} />
          <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
            <Typography>Subtotal</Typography>
            <Typography className="num" sx={{ fontWeight: 700 }}>
              {formatBRL(c.totals.subtotal_cents)}
            </Typography>
          </Box>
          <FreeShippingProgress progress={c.free_shipping_progress} />
          <Stack spacing={2} sx={{ mt: 3 }}>
            <Button component={RouterLink} to="/carrinho" variant="outlined" onClick={close}>
              Ver carrinho
            </Button>
            <Button component={RouterLink} to="/checkout" color="secondary" onClick={close}>
              Finalizar compra
            </Button>
          </Stack>
        </>
      ) : (
        <Typography color="text.secondary">Seu carrinho está vazio.</Typography>
      )}
    </Drawer>
  );
}
