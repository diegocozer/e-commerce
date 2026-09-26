import AddIcon from '@mui/icons-material/AddOutlined';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import { Avatar, Button, Chip, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import type { BulkProductAction } from '@/shared/api/extraTypes';
import { flattenCategories, useBrandOptions, useCategoryTree } from '@/shared/api/lookups';
import type { AdminProductListItem } from '@/shared/api/types';
import { Can, useCan } from '@/shared/auth';
import { formatBRL } from '@/shared/formatters/money';
import { formatStock, SALE_UNIT_ABBR, SALE_UNIT_LABEL } from '@/shared/formatters/quantity';
import { useListParams } from '@/shared/hooks/useListParams';
import { ActiveChip, ConfirmDialog, DataTable, FilterSelect, ListToolbar, notify, PageHeader, type Column } from '@/shared/ui';
import { useBulkProducts, useProducts } from '../api';

const columns: Column<AdminProductListItem>[] = [
  {
    key: 'image',
    header: 'Imagem',
    width: 56,
    hideBelow: 'sm',
    render: (p) => (
      <Avatar variant="rounded" src={p.image?.urls?.w300} alt={p.image?.alt ?? ''} sx={{ width: 40, height: 40, bgcolor: 'action.hover' }}>
        <ImageIcon fontSize="small" color="disabled" />
      </Avatar>
    ),
  },
  {
    key: 'name',
    header: 'Nome',
    sortKey: 'name',
    render: (p) => (
      <>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>
          {p.name}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {p.primary_category.name}
          {p.brand ? ` · ${p.brand.name}` : ''} · {SALE_UNIT_LABEL[p.sale_unit]}
        </Typography>
      </>
    ),
  },
  { key: 'sku', header: 'SKU', hideBelow: 'md', render: (p) => p.skus.join(', ') + (p.variants_count > p.skus.length ? '…' : '') },
  { key: 'price', header: 'Preço', align: 'right', sortKey: 'min_price', render: (p) => `${p.variants_count > 1 ? 'a partir de ' : ''}${formatBRL(p.min_price_cents)} /${SALE_UNIT_ABBR[p.sale_unit]}` },
  {
    key: 'stock',
    header: 'Estoque',
    align: 'right',
    render: (p) => (
      <span style={{ whiteSpace: 'nowrap' }}>
        {formatStock(p.total_available, SALE_UNIT_ABBR[p.sale_unit])} {p.has_low_stock && <Chip size="small" color="warning" label="Baixo" />}
      </span>
    ),
  },
  { key: 'status', header: 'Status', render: (p) => (p.deleted_at ? <Chip size="small" label="Excluído" /> : <ActiveChip active={p.is_active} />) },
];

export default function ProductsListPage() {
  const navigate = useNavigate();
  const canManage = useCan('products.manage');
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: '-updated_at' });
  const list = useProducts(apiParams);
  const cats = flattenCategories(useCategoryTree().data);
  const brands = useBrandOptions().data ?? [];
  const [selected, setSelected] = useState<(string | number)[]>([]);
  const [confirm, setConfirm] = useState<BulkProductAction | null>(null);
  const [bulkCategory, setBulkCategory] = useState<number | ''>('');
  const bulk = useBulkProducts();

  const runBulk = (action: BulkProductAction) => {
    bulk.mutate(
      { ids: selected.map(Number), action, category_id: action === 'set_primary_category' ? Number(bulkCategory) : undefined },
      {
        onSuccess: (r) => {
          setSelected([]);
          setConfirm(null);
          if (r.failed.length) notify.warning(`${r.succeeded.length} atualizados; ${r.failed.length} com erro: ${r.failed.map((f) => `#${f.id} ${f.errors.join(' ')}`).join('; ')}`);
          else notify.success(`${r.succeeded.length} produtos atualizados`);
        },
        onError: (e) => notify.error(errorMessage(e)),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Produtos"
        count={list.data?.meta.total}
        actions={
          <Can perm="products.manage">
            <Button variant="contained" startIcon={<AddIcon />} component={RouterLink} to="/produtos/novo">
              Novo produto
            </Button>
          </Can>
        }
      />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Buscar por nome ou SKU…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Categoria" value={params.filters.category_id ?? ''} onChange={(v) => update({ category_id: v })} options={cats.map((c) => ({ value: String(c.id), label: c.label }))} />
        <FilterSelect label="Marca" value={params.filters.brand_id ?? ''} onChange={(v) => update({ brand_id: v })} options={brands.map((b) => ({ value: String(b.id), label: b.name }))} width={150} />
        <FilterSelect label="Status" value={params.filters.status ?? ''} onChange={(v) => update({ status: v })} options={[{ value: 'active', label: 'Ativos' }, { value: 'inactive', label: 'Inativos' }]} width={140} />
        <FilterSelect label="Unidade" value={params.filters.sale_unit ?? ''} onChange={(v) => update({ sale_unit: v })} options={Object.entries(SALE_UNIT_LABEL).map(([value, label]) => ({ value, label }))} width={160} />
        <FilterSelect label="Estoque" value={params.filters.low_stock ?? ''} onChange={(v) => update({ low_stock: v })} options={[{ value: '1', label: 'Estoque baixo' }]} width={150} />
      </ListToolbar>
      <DataTable
        caption="Lista de produtos"
        columns={columns}
        rows={list.data?.data}
        rowKey={(p) => p.id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        onRowClick={(p) => navigate(`/produtos/${p.id}`)}
        selectable={canManage}
        selected={selected}
        onSelectedChange={setSelected}
        bulkActions={
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
            <Button size="small" onClick={() => runBulk('activate')}>Ativar</Button>
            <Button size="small" onClick={() => runBulk('deactivate')}>Desativar</Button>
            <TextField select size="small" label="Nova categoria" value={bulkCategory} onChange={(e) => setBulkCategory(Number(e.target.value))} sx={{ width: 200 }}>
              {cats.map((c) => (
                <MenuItem key={c.id} value={c.id}>
                  {c.label}
                </MenuItem>
              ))}
            </TextField>
            <Button size="small" disabled={bulkCategory === ''} onClick={() => runBulk('set_primary_category')}>
              Alterar categoria
            </Button>
            <Button size="small" color="error" onClick={() => setConfirm('delete')}>
              Excluir
            </Button>
          </Stack>
        }
        emptyTitle="Nenhum produto cadastrado"
        emptyAction={
          canManage ? (
            <Button variant="contained" component={RouterLink} to="/produtos/novo" startIcon={<AddIcon />}>
              Novo produto
            </Button>
          ) : undefined
        }
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
      <ConfirmDialog
        open={confirm === 'delete'}
        title={`Excluir ${selected.length} ${selected.length === 1 ? 'produto' : 'produtos'}?`}
        description="Os produtos e suas variantes serão excluídos (os pedidos antigos mantêm o histórico)."
        confirmLabel="Excluir"
        destructive
        loading={bulk.isPending}
        onConfirm={() => runBulk('delete')}
        onClose={() => setConfirm(null)}
      />
    </>
  );
}
