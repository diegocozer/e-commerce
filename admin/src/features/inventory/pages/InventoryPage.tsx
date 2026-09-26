import { Button, FormControlLabel, Stack, Switch, Typography } from '@mui/material';
import { useState } from 'react';
import { flattenCategories, useCategoryTree } from '@/shared/api/lookups';
import type { InventoryItem } from '@/shared/api/types';
import { useCanFn } from '@/shared/auth';
import { SALE_UNIT_LABEL, formatStock } from '@/shared/formatters/quantity';
import { useListParams } from '@/shared/hooks/useListParams';
import { DataTable, FilterSelect, ListToolbar, PageHeader, type Column } from '@/shared/ui';
import { useInventory } from '../api';
import { MovementsDrawer } from '../components/MovementsDrawer';
import { AdjustDialog, EntryDialog, ThresholdDialog } from '../components/StockDialogs';

type Action = { kind: 'entry' | 'adjust' | 'history' | 'threshold'; item: InventoryItem };

export default function InventoryPage() {
  const can = useCanFn();
  const { params, apiParams, update, clear, activeFilterCount } = useListParams({ sort: 'sku' });
  const list = useInventory(apiParams);
  const cats = flattenCategories(useCategoryTree().data);
  const [action, setAction] = useState<Action | null>(null);
  const open = (kind: Action['kind'], item: InventoryItem) => setAction({ kind, item });

  const columns: Column<InventoryItem>[] = [
    {
      key: 'product',
      header: 'Produto / variante',
      render: (i) => (
        <>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {i.product.name}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {i.variant_name}
            {!i.product.is_active && ' · produto inativo'}
          </Typography>
        </>
      ),
    },
    { key: 'sku', header: 'SKU', sortKey: 'sku', render: (i) => i.sku },
    { key: 'on_hand', header: 'Em mãos', align: 'right', render: (i) => formatStock(i.on_hand, i.stock_unit_abbr) },
    { key: 'reserved', header: 'Reservado', align: 'right', render: (i) => formatStock(i.reserved, i.stock_unit_abbr) },
    {
      key: 'available',
      header: 'Disponível',
      align: 'right',
      sortKey: 'available',
      render: (i) => (
        <Typography variant="body2" className="num" sx={{ fontWeight: 600, color: i.is_low_stock ? 'warning.main' : undefined, whiteSpace: 'nowrap' }}>
          {formatStock(i.available, i.stock_unit_abbr)} {i.is_low_stock && '⚠ Baixo'}
        </Typography>
      ),
    },
    { key: 'min', header: 'Mínimo', align: 'right', hideBelow: 'md', render: (i) => formatStock(i.low_stock_threshold, i.stock_unit_abbr) },
    {
      key: 'actions',
      header: 'Ações',
      render: (i) => (
        <Stack direction="row" spacing={1} onClick={(e) => e.stopPropagation()}>
          {can('inventory.move') && <Button size="small" onClick={() => open('entry', i)}>Entrada</Button>}
          {can('inventory.adjust') && <Button size="small" onClick={() => open('adjust', i)}>Ajustar</Button>}
          <Button size="small" onClick={() => open('history', i)}>Histórico</Button>
          {can('inventory.adjust') && <Button size="small" onClick={() => open('threshold', i)}>Mínimo</Button>}
        </Stack>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Estoque" count={list.data?.meta.total} subtitle="Disponível = em mãos − reservado. Alterações exigem movimento com motivo." />
      <ListToolbar q={params.q} onSearch={(q) => update({ q })} placeholder="Nome ou SKU…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Categoria" value={params.filters.category_id ?? ''} onChange={(v) => update({ category_id: v })} options={cats.map((c) => ({ value: String(c.id), label: c.label }))} />
        <FilterSelect label="Unidade" value={params.filters.sale_unit ?? ''} onChange={(v) => update({ sale_unit: v })} options={Object.entries(SALE_UNIT_LABEL).map(([value, label]) => ({ value, label }))} width={160} />
        <FormControlLabel control={<Switch checked={params.filters.low_stock === '1'} onChange={(e) => update({ low_stock: e.target.checked ? '1' : null })} />} label="Somente estoque baixo" />
      </ListToolbar>
      <DataTable
        caption="Estoque por variante"
        columns={columns}
        rows={list.data?.data}
        rowKey={(i) => i.variant_id}
        meta={list.data?.meta}
        loading={list.isPending}
        fetching={list.isFetching}
        error={list.error}
        onRetry={() => void list.refetch()}
        sort={params.sort}
        onSortChange={(sort) => update({ sort })}
        onPageChange={(page) => update({ page }, false)}
        onPerPageChange={(per_page) => update({ per_page })}
        emptyTitle="Nenhuma variante cadastrada"
        filtered={activeFilterCount > 0}
        onClearFilters={clear}
      />
      {action?.kind === 'entry' && <EntryDialog item={action.item} onClose={() => setAction(null)} />}
      {action?.kind === 'adjust' && <AdjustDialog item={action.item} onClose={() => setAction(null)} />}
      {action?.kind === 'threshold' && <ThresholdDialog item={action.item} onClose={() => setAction(null)} />}
      {action?.kind === 'history' && <MovementsDrawer item={action.item} onClose={() => setAction(null)} />}
    </>
  );
}
