import { Link } from '@mui/material';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import type { AdminVariantPickerItem, PriceTier } from '@/shared/api/types';
import { PRICE_LIST_KIND } from '@/shared/formatters/labels';
import { formatBp, formatBRL } from '@/shared/formatters/money';
import { formatQuantity } from '@/shared/formatters/quantity';
import { useListParams } from '@/shared/hooks/useListParams';
import { DataTable, ErrorState, ListToolbar, LoadingBlock, PageHeader, type Column } from '@/shared/ui';
import { usePriceList, usePriceListTiers } from '../api';

type Row = PriceTier & { variant: AdminVariantPickerItem };

const columns: Column<Row>[] = [
  { key: 'sku', header: 'Variante', render: (t) => <Link component={RouterLink} to={`/produtos/${t.variant.product.id}`}>{t.variant.sku} — {t.variant.product.name}</Link> },
  { key: 'min', header: 'A partir de', align: 'right', render: (t) => formatQuantity(t.min_quantity, t.variant.sale_unit) },
  { key: 'base', header: 'Preço base', align: 'right', render: (t) => formatBRL(t.variant.price_cents) },
  { key: 'price', header: 'Preço da tabela', align: 'right', render: (t) => formatBRL(t.price_cents) },
];

export default function PriceListDetailPage() {
  const id = Number(useParams().id);
  const pl = usePriceList(id);
  const { params, apiParams, update } = useListParams();
  const tiers = usePriceListTiers(id, apiParams);
  useBreadcrumb(pl.data?.name);
  if (pl.isPending) return <LoadingBlock />;
  if (pl.error || !pl.data) return <ErrorState error={pl.error} onRetry={() => void pl.refetch()} />;
  return (
    <>
      <PageHeader
        title={`Tabela: ${pl.data.name}`}
        back={{ to: '/precos/tabelas', label: 'Tabelas de preço' }}
        subtitle={`${PRICE_LIST_KIND[pl.data.kind]}${pl.data.discount_bp ? ` · ${formatBp(pl.data.discount_bp)} de desconto sobre a base` : ''} · faixas por variante são editadas no produto (seção Faixas de preço).`}
      />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="SKU ou nome…" />
      <DataTable caption="Faixas da tabela" columns={columns} rows={tiers.data?.data} rowKey={(t) => t.id} meta={tiers.data?.meta} loading={tiers.isPending} fetching={tiers.isFetching} error={tiers.error} onRetry={() => void tiers.refetch()} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} emptyTitle="Nenhuma faixa nesta tabela" />
    </>
  );
}
