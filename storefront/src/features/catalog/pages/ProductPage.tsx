import ContentCopyOutlined from '@mui/icons-material/ContentCopyOutlined';
import ExpandMore from '@mui/icons-material/ExpandMore';
import ShoppingCartOutlined from '@mui/icons-material/ShoppingCartOutlined';
import StorefrontOutlined from '@mui/icons-material/StorefrontOutlined';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Divider from '@mui/material/Divider';
import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import Link from '@mui/material/Link';
import Paper from '@mui/material/Paper';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate, useParams, useSearchParams } from 'react-router';
import { useAuth } from '@/features/auth';
import { useAddToCartFlow } from '@/features/cart/hooks/useAddToCartFlow';
import { toApiError } from '@/shared/api/errors';
import type { ProductDetail, ProductVariant } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatCEP } from '@/shared/formatters/postalCode';
import type { ConfigField } from '@/shared/saleUnit/configuration';
import { UNIT_CODE } from '@/shared/saleUnit/labels';
import { AppBreadcrumbs } from '@/shared/ui/AppBreadcrumbs';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Price } from '@/shared/ui/Price';
import { SafeHtml } from '@/shared/ui/SafeHtml';
import { Seo } from '@/shared/ui/Seo';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { StockBadge } from '@/shared/ui/StockBadge';
import { NotFoundPage } from '@/features/errors/pages/NotFoundPage';
import { Gallery } from '../components/Gallery';
import { ProductCarousel } from '../components/ProductCarousel';
import { QuantityConfigurator, type ConfiguratorState } from '../components/QuantityConfigurator';
import { ShippingEstimator } from '../components/ShippingEstimator';
import { SpecsTable } from '../components/SpecsTable';
import { TiersTable } from '../components/TiersTable';
import { VariantSelector } from '../components/VariantSelector';
import { useProduct, useRelated, useSettings } from '../hooks/queries';

/** JSON-LD de fallback quando a API não enviar `seo.json_ld` (UX §4.4.8). */
function fallbackJsonLd(p: ProductDetail): Record<string, unknown>[] {
  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  return [
    {
      '@context': 'https://schema.org',
      '@type': 'Product',
      name: p.name,
      sku: p.variants.find((v) => v.id === p.default_variant_id)?.sku,
      brand: p.brand ? { '@type': 'Brand', name: p.brand.name } : undefined,
      image: p.images.flatMap((i) => (i.urls ? [i.urls.w1600] : [])),
      offers: p.variants.map((v) => ({
        '@type': 'Offer',
        sku: v.sku,
        price: (v.price.unit_price_cents / 100).toFixed(2),
        priceCurrency: 'BRL',
        availability: v.availability.status === 'out_of_stock' ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
        url: `${origin}${p.url_path}?sku=${v.sku}`,
        priceSpecification: { '@type': 'UnitPriceSpecification', price: (v.price.unit_price_cents / 100).toFixed(2), priceCurrency: 'BRL', unitCode: UNIT_CODE[p.sale_unit] },
      })),
    },
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: p.breadcrumbs.map((b, i) => ({ '@type': 'ListItem', position: i + 1, name: b.name, ...(b.url_path ? { item: `${origin}${b.url_path}` } : {}) })),
    },
  ];
}

export default function ProductPage() {
  const { productSlug = '' } = useParams();
  const q = useProduct(productSlug);
  const location = useLocation();
  const navigate = useNavigate();

  // Canônico: path diferente de url_path → replace (API §3.A).
  useEffect(() => {
    if (q.data && q.data.url_path !== location.pathname) navigate(`${q.data.url_path}${location.search}`, { replace: true });
  }, [q.data, location.pathname, location.search, navigate]);

  if (q.isLoading) return <ProductSkeleton />;
  if (q.error) {
    const e = toApiError(q.error);
    if (e.status === 404) {
      const category = e.extra<{ slug: string; name: string; url_path: string } | null>('category');
      return <NotFoundPage message="Este produto não está mais disponível." category={category ?? null} />;
    }
    return <ErrorState title="Não foi possível carregar o produto." error={q.error} onRetry={() => void q.refetch()} />;
  }
  if (!q.data) return null;
  return <ProductView product={q.data} />;
}

function ProductSkeleton() {
  return (
    <Box aria-busy="true">
      <Seo title="Carregando…" />
      <span className="visually-hidden">Carregando produto</span>
      <Grid container spacing={6}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Skeleton variant="rectangular" sx={{ aspectRatio: '1 / 1', height: 'auto', borderRadius: 2 }} />
        </Grid>
        <Grid size={{ xs: 12, md: 5 }}>
          <Skeleton height={48} />
          <Skeleton height={32} width="60%" />
          <Skeleton height={56} width="40%" />
          <Skeleton variant="rectangular" height={64} sx={{ my: 2 }} />
          <Skeleton variant="rectangular" height={64} sx={{ my: 2 }} />
          <Skeleton variant="rectangular" height={64} />
        </Grid>
      </Grid>
    </Box>
  );
}

function ProductView({ product }: { product: ProductDetail }) {
  const [params, setParams] = useSearchParams();
  const skuParam = params.get('sku');
  // Variante selecionada vive na URL (?sku=), sem novo histórico (UX §4.4.3).
  const variant = useMemo(
    () => product.variants.find((v) => v.sku === skuParam) ?? product.variants.find((v) => v.id === product.default_variant_id) ?? product.variants[0],
    [product, skuParam],
  );

  const [cfg, setCfg] = useState<ConfiguratorState | null>(null);
  const [externalErrors, setExternalErrors] = useState<Partial<Record<ConfigField, string>>>({});
  const [maxAvailable, setMaxAvailable] = useState<number | null>(null);
  const { addToCart, isPending } = useAddToCartFlow();
  const { isAuthenticated } = useAuth();
  const notify = useSnackbar();
  const settings = useSettings();
  const related = useRelated(product.slug);

  const selectVariant = (v: ProductVariant) => {
    setExternalErrors({});
    setMaxAvailable(null);
    const next = new URLSearchParams(params);
    next.set('sku', v.sku);
    setParams(next, { replace: true });
  };

  const images = useMemo(() => {
    const own = product.images.filter((i) => variant.image_ids.includes(i.id));
    return own.length ? [...own, ...product.images.filter((i) => !variant.image_ids.includes(i.id))] : product.images;
  }, [product.images, variant.image_ids]);

  const onAdd = async () => {
    if (!cfg?.valid) return;
    setExternalErrors({});
    const res = await addToCart({ variant_id: variant.id, ...cfg.valid.body }, { productName: product.name, configurationLabel: cfg.configurationLabel, unit: product.sale_unit });
    if (!res.ok) {
      if (res.availableQuantity !== null) setMaxAvailable(res.availableQuantity);
      if (res.error.isValidation) {
        const fe: Partial<Record<ConfigField, string>> = {};
        for (const [k, m] of Object.entries(res.error.fieldErrors)) {
          if (k === 'quantity' || k === 'width_m' || k === 'height_m' || k === 'pieces') fe[k] = m[0];
          else notify(m[0], { severity: 'error' });
        }
        setExternalErrors(fe);
      }
    }
  };

  const copySku = async () => {
    try {
      await navigator.clipboard.writeText(variant.sku);
      notify('SKU copiado', { severity: 'info' });
    } catch {
      /* sem clipboard */
    }
  };

  const unavailable = variant.availability.status === 'out_of_stock';
  const disabledReason = cfg?.blockedReason ?? null;
  const ctaDisabled = unavailable || Boolean(disabledReason) || !cfg?.valid;
  const ctaLabel = unavailable ? 'Indisponível' : disabledReason && disabledReason !== 'Revise a quantidade' ? disabledReason : 'Adicionar ao carrinho';
  const whatsapp = settings.data?.store.whatsapp;
  const pickup = settings.data?.pickup_points[0];
  const activeTierMin = cfg?.preview?.applied_tier?.min_quantity ?? cfg?.local?.tier?.min_quantity ?? null;

  return (
    <Box sx={{ pb: { xs: 24, md: 0 } }}>
      <Seo
        title={product.seo.title}
        description={product.seo.description}
        canonical={product.seo.canonical_url}
        robots={product.seo.robots}
        ogImage={product.seo.og_image_url}
        jsonLd={product.seo.json_ld.length ? product.seo.json_ld : fallbackJsonLd(product)}
      />
      <AppBreadcrumbs items={product.breadcrumbs} />
      <Grid container spacing={{ xs: 4, md: 8 }}>
        <Grid size={{ xs: 12, md: 7 }}>
          <Gallery images={images} name={product.name} />
          <Box sx={{ display: { xs: 'none', md: 'block' }, mt: 8 }}>
            <DescriptionBlocks product={product} />
          </Box>
        </Grid>
        <Grid size={{ xs: 12, md: 5 }}>
          <Box sx={{ position: { md: 'sticky' }, top: { md: 16 }, display: 'flex', flexDirection: 'column', gap: 4 }}>
            <Box>
              <PageHeading sx={{ mb: 1 }}>{product.name}</PageHeading>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 2 }}>
                <Typography variant="caption" color="text.secondary">
                  SKU {variant.sku}
                </Typography>
                <IconButton size="small" aria-label={`Copiar SKU ${variant.sku}`} onClick={copySku} sx={{ minWidth: 32, minHeight: 32 }}>
                  <ContentCopyOutlined fontSize="inherit" />
                </IconButton>
                {product.brand ? (
                  <Link component={RouterLink} to={`/busca?q=${encodeURIComponent(product.brand.name)}`} variant="caption">
                    {product.brand.name}
                  </Link>
                ) : null}
                <StockBadge status={variant.availability.status} availableQuantity={variant.availability.available_quantity} unit={product.sale_unit} />
              </Box>
              {product.short_description ? (
                <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
                  {product.short_description}
                </Typography>
              ) : null}
            </Box>

            <Box>
              <Price
                cents={variant.price.unit_price_cents}
                unit={product.sale_unit}
                compareAtCents={variant.price.compare_at_cents}
                sourceLabel={variant.price.price_source_label}
                longUnit
                variant="price"
              />
              {!isAuthenticated ? (
                <Typography variant="body2" sx={{ mt: 1 }}>
                  <Link component={RouterLink} to={`/entrar?redirect=${encodeURIComponent(product.url_path)}`}>
                    Tem CNPJ? Entre para ver seu preço
                  </Link>
                </Typography>
              ) : null}
            </Box>

            <TiersTable tiers={variant.price.tiers} unit={product.sale_unit} activeMin={activeTierMin} />
            <VariantSelector product={product} selected={variant} onSelect={selectVariant} />
            {product.sale_unit === 'LINEAR_METER' && variant.rules.fixed_width_m !== null ? (
              <Typography variant="body2">
                Largura: <strong className="num">{variant.rules.fixed_width_m.toFixed(2).replace('.', ',')}&nbsp;m</strong>
              </Typography>
            ) : null}

            <QuantityConfigurator
              key={variant.id}
              productSlug={product.slug}
              productName={product.name}
              variant={variant}
              onStateChange={setCfg}
              maxAvailable={maxAvailable}
              externalErrors={externalErrors}
            />

            <Box sx={{ display: { xs: 'none', md: 'block' } }}>
              <Button color="secondary" fullWidth startIcon={<ShoppingCartOutlined />} onClick={onAdd} disabled={ctaDisabled} loading={isPending}>
                {ctaLabel}
              </Button>
            </Box>
            {unavailable ? (
              <Alert severity="info">
                Produto indisponível no momento.{' '}
                {whatsapp ? (
                  <Link href={`https://wa.me/55${whatsapp}?text=${encodeURIComponent(`Previsão para ${product.name} (${variant.sku})?`)}`} target="_blank" rel="noopener">
                    Fale no WhatsApp para previsão
                  </Link>
                ) : null}
              </Alert>
            ) : null}

            <Divider />
            {product.pickup_only ? (
              <Alert icon={<StorefrontOutlined />} severity="info">
                Disponível somente para retirada na loja
                {pickup ? ` em ${pickup.city}/${pickup.state}: ${pickup.street}, ${pickup.number} – ${formatCEP(pickup.postal_code)}${pickup.opening_hours ? ` (${pickup.opening_hours})` : ''}` : ''}.
              </Alert>
            ) : !unavailable ? (
              <ShippingEstimator variantId={variant.id} line={cfg?.valid?.body ?? null} configurationLabel={cfg?.configurationLabel ?? ''} />
            ) : null}
          </Box>
        </Grid>
      </Grid>

      <Box sx={{ display: { xs: 'block', md: 'none' }, mt: 6 }}>
        <DescriptionBlocks product={product} accordion />
      </Box>

      <ProductCarousel title="Produtos relacionados" products={related.data} loading={related.isLoading} />

      {/* Barra fixa inferior (mobile): total + CTA */}
      <Paper
        elevation={8}
        sx={{ display: { xs: 'flex', md: 'none' }, position: 'fixed', left: 0, right: 0, bottom: 0, zIndex: 10, p: 3, gap: 3, alignItems: 'center', justifyContent: 'space-between' }}
      >
        <Typography variant="total" component="p" sx={{ opacity: cfg?.recalculating ? 0.6 : 1 }}>
          {cfg?.totalCents != null ? formatBRL(cfg.totalCents) : '—'}
        </Typography>
        <Button color="secondary" onClick={onAdd} disabled={ctaDisabled} loading={isPending} aria-label={`${ctaLabel} — ${product.name}`}>
          {ctaLabel}
        </Button>
      </Paper>
    </Box>
  );
}

function DescriptionBlocks({ product, accordion }: { product: ProductDetail; accordion?: boolean }) {
  const blocks = [
    product.description_html ? { id: 'desc', title: 'Descrição', body: <SafeHtml html={product.description_html} /> } : null,
    product.specifications.length ? { id: 'specs', title: 'Especificações técnicas', body: <SpecsTable specs={product.specifications} /> } : null,
  ].filter((b): b is { id: string; title: string; body: React.JSX.Element } => b !== null);
  if (accordion) {
    return (
      <>
        {blocks.map((b, i) => (
          <Accordion key={b.id} defaultExpanded={i === 0} disableGutters>
            <AccordionSummary expandIcon={<ExpandMore />} aria-controls={`${b.id}-content`} id={`${b.id}-header`}>
              <Typography variant="h4" component="h2">
                {b.title}
              </Typography>
            </AccordionSummary>
            <AccordionDetails>{b.body}</AccordionDetails>
          </Accordion>
        ))}
      </>
    );
  }
  return (
    <>
      {blocks.map((b) => (
        <Box component="section" key={b.id} sx={{ mb: 6 }} aria-labelledby={`${b.id}-h`}>
          <Typography variant="h3" component="h2" id={`${b.id}-h`} sx={{ mb: 2 }}>
            {b.title}
          </Typography>
          {b.body}
        </Box>
      ))}
    </>
  );
}
