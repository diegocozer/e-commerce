import LocalShippingOutlined from '@mui/icons-material/LocalShippingOutlined';
import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined';
import StorefrontOutlined from '@mui/icons-material/StorefrontOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import { useAuth } from '@/features/auth';
import { ReorderSuggestions } from '@/features/account';
import { formatBRL } from '@/shared/formatters/money';
import { ErrorState } from '@/shared/ui/ErrorState';
import { Seo } from '@/shared/ui/Seo';
import { ProductCarousel } from '../components/ProductCarousel';
import { useCategoryTree, useProducts, useSettings } from '../hooks/queries';

export default function HomePage() {
  const { isAuthenticated } = useAuth();
  const tree = useCategoryTree();
  const settings = useSettings();
  const best = useProducts({ sort: 'best_selling', per_page: 10 });
  const promos = useProducts({ on_sale: 1, per_page: 10 });
  const featured = useProducts({ featured: 1, per_page: 10 });
  const banner = settings.data?.free_shipping_banner;
  const pickup = settings.data?.pickup_points[0];
  const benefits = [
    { icon: <StorefrontOutlined />, text: pickup ? `Retirada grátis em ${pickup.city}` : 'Retirada grátis na loja' },
    { icon: <LocalShippingOutlined />, text: 'Entrega própria na região' },
    { icon: <Inventory2Outlined />, text: banner?.enabled ? banner.text || `Frete grátis acima de ${formatBRL(banner.threshold_cents)}` : 'Frete para todo o Brasil' },
  ];
  const origin = typeof window !== 'undefined' ? window.location.origin : '';

  return (
    <Box>
      <Seo
        title="Comunika Suprimentos — suprimentos para comunicação visual"
        description="Lonas, vinis, adesivos, papéis e acessórios para comunicação visual. Venda por metro, m², rolo, kg e caixa."
        canonical={`${origin}/`}
        jsonLd={[{ '@context': 'https://schema.org', '@type': 'Organization', name: 'Comunika Suprimentos', url: `${origin}/` }]}
      />
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, md: 8 }}>
          <Box sx={{ bgcolor: 'primary.light', borderRadius: 3, p: { xs: 6, md: 10 }, minHeight: { xs: 200, md: 280 }, display: 'flex', flexDirection: 'column', justifyContent: 'center', gap: 4 }}>
            <Typography variant="h1" sx={{ color: 'primary.main', maxWidth: 560 }}>
              Lonas e vinis com entrega própria em Blumenau
            </Typography>
            <Typography>Suprimentos para comunicação visual, do jeito que você compra: por metro, m², rolo ou caixa.</Typography>
            <Stack direction="row" spacing={2}>
              <Button component={RouterLink} to="/lonas">Ver lonas</Button>
              <Button component={RouterLink} to="/vinis" variant="outlined">Ver vinis</Button>
            </Stack>
          </Box>
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <Card sx={{ p: 4, height: '100%' }}>
            {isAuthenticated ? (
              <>
                <Typography variant="h3" component="h2" sx={{ mb: 3 }}>Compre de novo</Typography>
                <ReorderSuggestions limit={3} />
                <Link component={RouterLink} to="/conta/pedidos" sx={{ display: 'block', mt: 3 }}>Ver pedidos anteriores</Link>
              </>
            ) : (
              <>
                <Typography variant="h3" component="h2" sx={{ mb: 2 }}>Compra para sua empresa?</Typography>
                <Typography sx={{ mb: 3 }}>Cadastre seu CNPJ e acesse preços de atacado.</Typography>
                <Button component={RouterLink} to="/cadastro" variant="outlined">Criar conta PJ</Button>
              </>
            )}
          </Card>
        </Grid>
      </Grid>

      <Box component="ul" sx={{ listStyle: 'none', p: 0, my: 6, display: 'flex', gap: 3, overflowX: 'auto' }}>
        {benefits.map((b) => (
          <Box component="li" key={b.text} sx={{ display: 'flex', alignItems: 'center', gap: 2, px: 4, py: 2, border: 1, borderColor: 'divider', borderRadius: 4, bgcolor: 'background.paper', whiteSpace: 'nowrap' }}>
            <Box aria-hidden sx={{ color: 'primary.main', display: 'flex' }}>{b.icon}</Box>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{b.text}</Typography>
          </Box>
        ))}
      </Box>

      <Box component="section" aria-labelledby="home-cats">
        <Typography variant="h2" id="home-cats" sx={{ mb: 3 }}>Categorias</Typography>
        {tree.error ? (
          <ErrorState compact title="Não foi possível carregar as categorias." onRetry={() => void tree.refetch()} />
        ) : (
          <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, display: 'grid', gridTemplateColumns: { xs: 'repeat(3, 1fr)', md: 'repeat(5, 1fr)' }, gap: 3 }}>
            {(tree.data ?? Array.from({ length: 10 }, () => null)).map((c, i) => (
              <li key={c?.id ?? i}>
                {c ? (
                  <Card component={RouterLink} to={c.url_path} sx={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2, p: 3, textDecoration: 'none', color: 'text.primary', '&:hover': { borderColor: 'primary.main' } }}>
                    {c.image_url ? <img src={c.image_url} alt="" width={56} height={56} loading="lazy" style={{ objectFit: 'contain' }} /> : <Inventory2Outlined sx={{ fontSize: 40, color: 'primary.main' }} aria-hidden />}
                    <Typography sx={{ fontWeight: 600, textAlign: 'center', fontSize: 14 }}>{c.name}</Typography>
                  </Card>
                ) : (
                  <Skeleton variant="rectangular" height={100} sx={{ borderRadius: 3 }} />
                )}
              </li>
            ))}
          </Box>
        )}
      </Box>

      <ProductCarousel title="Mais vendidos" products={best.data?.data} loading={best.isLoading} />
      <ProductCarousel title="Promoções" products={promos.data?.data} loading={promos.isLoading} />
      <ProductCarousel title="Destaques" products={featured.data?.data} loading={featured.isLoading} />
    </Box>
  );
}
