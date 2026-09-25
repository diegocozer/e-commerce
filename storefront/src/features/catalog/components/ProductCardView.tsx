import AddShoppingCartOutlined from '@mui/icons-material/AddShoppingCartOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { memo } from 'react';
import { Link as RouterLink } from 'react-router';
import type { ProductCard } from '@/shared/api/types';
import { Price } from '@/shared/ui/Price';
import { ProductImg } from '@/shared/ui/ProductImg';
import { StockBadge } from '@/shared/ui/StockBadge';

interface Props {
  product: ProductCard;
  layout?: 'grid' | 'list';
  /** Adição rápida (só UNIT/ROLL/BOX com quick_add). */
  onQuickAdd?: (product: ProductCard) => void;
  quickAddPending?: boolean;
}

/** Card de produto (UX §4.2): a área toda é um único link; preço sempre com unidade. */
export const ProductCardView = memo(function ProductCardView({ product, layout = 'grid', onQuickAdd, quickAddPending }: Props) {
  const list = layout === 'list';
  return (
    <Card
      component="article"
      sx={{
        position: 'relative', height: '100%', display: 'flex', flexDirection: list ? 'row' : 'column', gap: 3, p: 3,
        '&:hover': { borderColor: 'primary.main', boxShadow: 3 },
        '&:focus-within': { borderColor: 'primary.main' },
      }}
    >
      <Box sx={{ width: list ? 120 : '100%', flexShrink: 0 }}>
        <ProductImg image={product.image} alt={product.image?.alt ?? product.name} size={list ? 120 : undefined} />
      </Box>
      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, flex: 1, minWidth: 0 }}>
        <StockBadge status={product.availability.status} />
        <Typography variant="h4" component="h3" sx={{ fontSize: 16, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
          <Link
            component={RouterLink}
            to={product.url_path}
            color="inherit"
            underline="none"
            sx={{ '&::after': { content: '""', position: 'absolute', inset: 0 } }}
          >
            {product.name}
          </Link>
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {product.variants_count === 1 ? `SKU ${product.default_variant.sku}` : `${product.variants_count} opções`}
          {product.brand ? ` · ${product.brand.name}` : ''}
        </Typography>
        {product.key_attribute ? (
          <Typography variant="body2" color="text.secondary">
            {product.key_attribute}
          </Typography>
        ) : null}
        <Box sx={{ mt: 'auto' }}>
          <Price
            cents={product.price.unit_price_cents}
            unit={product.sale_unit}
            compareAtCents={product.price.compare_at_cents}
            sourceLabel={product.price.price_source_label}
          />
          {product.price.from_price_cents !== null && product.price.from_price_cents < product.price.unit_price_cents ? (
            <Box>
              <Price cents={product.price.from_price_cents} unit={product.sale_unit} variant="body2" fromPrice />
            </Box>
          ) : null}
        </Box>
        {onQuickAdd && product.quick_add ? (
          <Button
            variant="outlined"
            color="secondary"
            size="medium"
            startIcon={<AddShoppingCartOutlined />}
            onClick={() => onQuickAdd(product)}
            loading={quickAddPending}
            sx={{ position: 'relative', zIndex: 1, mt: 2 }}
            aria-label={`Adicionar ${product.name} ao carrinho`}
          >
            Adicionar
          </Button>
        ) : null}
      </Box>
    </Card>
  );
});

export function ProductCardSkeleton() {
  return (
    <Card sx={{ p: 3, display: 'flex', flexDirection: 'column', gap: 2 }} aria-hidden>
      <Box sx={{ aspectRatio: '1 / 1', bgcolor: 'grey.100', borderRadius: 2 }} />
      <Box sx={{ height: 16, width: '80%', bgcolor: 'grey.100', borderRadius: 1 }} />
      <Box sx={{ height: 16, width: '60%', bgcolor: 'grey.100', borderRadius: 1 }} />
      <Box sx={{ height: 22, width: '40%', bgcolor: 'grey.100', borderRadius: 1 }} />
    </Card>
  );
}
