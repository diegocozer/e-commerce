import Inventory2Outlined from '@mui/icons-material/Inventory2Outlined';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { Link as RouterLink, useParams } from 'react-router';
import { NotFoundPage } from '@/features/errors/pages/NotFoundPage';
import { toApiError } from '@/shared/api/errors';
import { isReservedSlug } from '@/shared/lib/reservedSlugs';
import { AppBreadcrumbs } from '@/shared/ui/AppBreadcrumbs';
import { EmptyState } from '@/shared/ui/EmptyState';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { SafeHtml } from '@/shared/ui/SafeHtml';
import { Seo } from '@/shared/ui/Seo';
import { ProductListing } from '../components/ProductListing';
import { useCategory, useProducts } from '../hooks/queries';
import { toApiFilters, useListingParams } from '../hooks/useListingParams';

export default function CategoryPage() {
  const { categorySlug = '' } = useParams();
  if (isReservedSlug(categorySlug)) return <NotFoundPage />;
  return <CategoryView slug={categorySlug} />;
}

function CategoryView({ slug }: { slug: string }) {
  const category = useCategory(slug);
  const { state } = useListingParams('category');
  const products = useProducts(toApiFilters(state, { category: slug }), !category.error);

  if (category.error) {
    const e = toApiError(category.error);
    if (e.status === 404) return <NotFoundPage />;
    return <ErrorState error={category.error} onRetry={() => void category.refetch()} />;
  }
  const c = category.data;
  return (
    <Box>
      {c ? (
        <Seo
          title={c.seo.title}
          description={c.seo.description}
          canonical={c.seo.canonical_url}
          robots={c.seo.robots}
          ogImage={c.seo.og_image_url}
          jsonLd={c.seo.json_ld}
        />
      ) : (
        <Seo title="Carregando…" />
      )}
      {c ? <AppBreadcrumbs items={c.breadcrumbs} /> : <Skeleton width={200} />}
      <Box sx={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 2, flexWrap: 'wrap' }}>
        {c ? <PageHeading>{c.name}</PageHeading> : <Skeleton variant="text" width={280} height={48} />}
        {products.data ? (
          <Typography color="text.secondary" className="num">
            {products.data.meta.total} produtos
          </Typography>
        ) : null}
      </Box>
      {c?.children.length ? (
        <Box sx={{ display: 'flex', gap: 2, overflowX: 'auto', mb: 4, pb: 1 }}>
          {c.children.map((child) => (
            <Chip key={child.id} component={RouterLink} to={child.url_path} clickable label={child.name} variant="outlined" />
          ))}
        </Box>
      ) : null}
      {c?.description_html ? <SafeHtml html={c.description_html} sx={{ color: 'text.secondary', mb: 4, fontSize: 14 }} /> : null}
      <ProductListing
        mode="category"
        data={products.data}
        isLoading={products.isLoading || category.isLoading}
        isFetching={products.isFetching}
        error={products.error}
        onRetry={() => void products.refetch()}
        subcategories={c?.children}
        emptyContent={<EmptyState icon={<Inventory2Outlined />} title="Em breve novos produtos nesta categoria." />}
      />
    </Box>
  );
}
