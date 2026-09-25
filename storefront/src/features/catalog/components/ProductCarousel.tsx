import ChevronLeft from '@mui/icons-material/ChevronLeft';
import ChevronRight from '@mui/icons-material/ChevronRight';
import Box from '@mui/material/Box';
import IconButton from '@mui/material/IconButton';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { useRef } from 'react';
import { Link as RouterLink } from 'react-router';
import type { ProductCard } from '@/shared/api/types';
import { ProductCardSkeleton, ProductCardView } from './ProductCardView';
import { useQuickAdd } from '../hooks/useQuickAdd';

/** Vitrine horizontal (lista <ul> rolável; sem carrossel automático — UX §6.12). */
export function ProductCarousel({ title, products, loading, seeAllHref }: { title: string; products?: ProductCard[]; loading?: boolean; seeAllHref?: string }) {
  const ref = useRef<HTMLUListElement>(null);
  const { quickAdd, pendingId } = useQuickAdd();
  if (!loading && (!products || products.length === 0)) return null;
  const scroll = (dir: 1 | -1) => ref.current?.scrollBy({ left: dir * ref.current.clientWidth * 0.8, behavior: 'smooth' });
  return (
    <Box component="section" sx={{ my: 8 }} aria-labelledby={`sec-${title}`}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
        <Typography variant="h2" id={`sec-${title}`}>
          {title}
        </Typography>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          {seeAllHref ? (
            <Link component={RouterLink} to={seeAllHref}>
              Ver todos ›
            </Link>
          ) : null}
          <IconButton aria-label="Ver produtos anteriores" onClick={() => scroll(-1)} sx={{ display: { xs: 'none', md: 'inline-flex' } }}>
            <ChevronLeft />
          </IconButton>
          <IconButton aria-label="Ver mais produtos" onClick={() => scroll(1)} sx={{ display: { xs: 'none', md: 'inline-flex' } }}>
            <ChevronRight />
          </IconButton>
        </Box>
      </Box>
      <Box
        component="ul"
        ref={ref}
        sx={{ listStyle: 'none', p: 0, m: 0, display: 'grid', gridAutoFlow: 'column', gridAutoColumns: { xs: '46%', sm: '31%', md: '19%' }, gap: 3, overflowX: 'auto', scrollSnapType: 'x mandatory', pb: 2 }}
      >
        {loading
          ? Array.from({ length: 5 }, (_, i) => (
              <li key={i}>
                <ProductCardSkeleton />
              </li>
            ))
          : products!.map((p) => (
              <Box component="li" key={p.id} sx={{ scrollSnapAlign: 'start' }}>
                <ProductCardView product={p} onQuickAdd={quickAdd} quickAddPending={pendingId === p.id} />
              </Box>
            ))}
      </Box>
    </Box>
  );
}
