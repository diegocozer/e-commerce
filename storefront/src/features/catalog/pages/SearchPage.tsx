import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { useEffect } from 'react';
import { Link as RouterLink, Navigate, useNavigate } from 'react-router';
import { EmptyState } from '@/shared/ui/EmptyState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { ProductListing } from '../components/ProductListing';
import { useCategoryTree, useProducts, useSettings } from '../hooks/queries';
import { toApiFilters, useListingParams } from '../hooks/useListingParams';

export default function SearchPage() {
  const { state, q } = useListingParams('search');
  const term = q.trim();
  const valid = term.length >= 2 && term.length <= 100;
  const products = useProducts(toApiFilters(state, { q: term }), valid);
  const navigate = useNavigate();
  const tree = useCategoryTree();
  const settings = useSettings();

  // Match exato de SKU e único resultado → vai direto ao produto (UX §4.3).
  const sku = products.data?.search?.exact_sku_match;
  useEffect(() => {
    if (sku && products.data?.meta.total === 1) navigate(`${sku.url_path}?sku=${encodeURIComponent(sku.sku)}`, { replace: true });
  }, [sku, products.data?.meta.total, navigate]);

  if (!term) return <Navigate to="/" replace />;
  const whatsapp = settings.data?.store.whatsapp;
  return (
    <Box>
      <Seo title={`Resultados para "${term}"`} robots="noindex,follow" />
      <PageHeading>Resultados para “{term}”</PageHeading>
      {!valid ? (
        <Alert severity="info">Digite pelo menos 2 caracteres.</Alert>
      ) : (
        <>
          {sku ? (
            <Alert severity="success" sx={{ mb: 3 }}>
              SKU {sku.sku} encontrado —{' '}
              <Link component={RouterLink} to={`${sku.url_path}?sku=${encodeURIComponent(sku.sku)}`}>
                ver produto
              </Link>
            </Alert>
          ) : null}
          <ProductListing
            mode="search"
            data={products.data}
            isLoading={products.isLoading}
            isFetching={products.isFetching}
            error={products.error}
            onRetry={() => void products.refetch()}
            emptyContent={
              <EmptyState
                icon={<SearchOutlined />}
                title={`Nenhum resultado para "${term}".`}
                text={
                  <>
                    Verifique a ortografia · Use termos mais gerais (ex.: “lona” em vez de “lona 440g fosca”) · Busque pelo SKU
                  </>
                }
                action={
                  <Box>
                    <Typography variant="body2" sx={{ mb: 2 }}>
                      {tree.data?.map((c, i) => (
                        <span key={c.id}>
                          {i ? ' · ' : ''}
                          <Link component={RouterLink} to={c.url_path}>{c.name}</Link>
                        </span>
                      ))}
                    </Typography>
                    {whatsapp ? (
                      <Link href={`https://wa.me/55${whatsapp}?text=${encodeURIComponent(`Procuro: ${term}`)}`} target="_blank" rel="noopener">
                        Não achou? Fale no WhatsApp
                      </Link>
                    ) : null}
                  </Box>
                }
              />
            }
          />
        </>
      )}
    </Box>
  );
}
