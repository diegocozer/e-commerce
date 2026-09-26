import {
  Box,
  Checkbox,
  LinearProgress,
  Paper,
  Skeleton,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TablePagination,
  TableRow,
  TableSortLabel,
  Toolbar,
  Typography,
} from '@mui/material';
import type { ReactNode } from 'react';
import type { Paginated } from '@/shared/api/types';
import { EmptyState, ErrorState } from './States';

export interface Column<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  /** Token de ordenação da API (allowlist do endpoint). */
  sortKey?: string;
  align?: 'left' | 'right' | 'center';
  width?: number | string;
  hideBelow?: 'sm' | 'md' | 'lg';
}

type Meta = Paginated<unknown>['meta'];

interface Props<T> {
  columns: Column<T>[];
  rows: T[] | undefined;
  rowKey: (row: T) => string | number;
  meta?: Meta;
  loading?: boolean;
  fetching?: boolean;
  error?: unknown;
  onRetry?: () => void;
  sort?: string;
  onSortChange?: (sort: string) => void;
  onPageChange?: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
  onRowClick?: (row: T) => void;
  selectable?: boolean;
  selected?: (string | number)[];
  onSelectedChange?: (ids: (string | number)[]) => void;
  bulkActions?: ReactNode;
  emptyTitle?: string;
  emptyAction?: ReactNode;
  filtered?: boolean;
  onClearFilters?: () => void;
  caption?: string;
}

/**
 * Tabela com paginação/ordenação no servidor (UX §5.4): skeleton na 1ª carga,
 * LinearProgress ao trocar página/filtro, vazio x vazio-por-filtro, erro com retry,
 * seleção + barra de ações em massa.
 */
export function DataTable<T>(props: Props<T>) {
  const { columns, rows, rowKey, meta, loading, fetching, error, onRetry, sort, onSortChange, onPageChange, onPerPageChange, onRowClick, selectable, selected = [], onSelectedChange, bulkActions, emptyTitle = 'Nenhum registro cadastrado', emptyAction, filtered, onClearFilters, caption } = props;

  const ids = (rows ?? []).map(rowKey);
  const allSelected = ids.length > 0 && ids.every((id) => selected.includes(id));
  const someSelected = ids.some((id) => selected.includes(id));
  const sortField = sort?.replace(/^-/, '');
  const sortDir: 'asc' | 'desc' = sort?.startsWith('-') ? 'desc' : 'asc';
  const colSpan = columns.length + (selectable ? 1 : 0);

  const hideSx = (c: Column<T>) => (c.hideBelow ? { display: { xs: 'none', [c.hideBelow]: 'table-cell' } } : {});

  if (error && !rows) {
    return <ErrorState error={error} onRetry={onRetry} />;
  }

  return (
    <Paper variant="outlined" sx={{ position: 'relative', overflow: 'hidden' }}>
      {fetching && !loading && <LinearProgress sx={{ position: 'absolute', top: 0, left: 0, right: 0 }} aria-label="Atualizando" />}
      {selectable && selected.length > 0 && (
        <Toolbar sx={{ bgcolor: 'primary.light', gap: 2, flexWrap: 'wrap' }} variant="dense">
          <Typography variant="body2" sx={{ fontWeight: 600 }} role="status">
            {selected.length} {selected.length === 1 ? 'selecionado' : 'selecionados'}
          </Typography>
          {bulkActions}
        </Toolbar>
      )}
      {error !== undefined && error !== null && rows && (
        <Box sx={{ p: 2 }}>
          <ErrorState error={error} onRetry={onRetry} />
        </Box>
      )}
      <TableContainer sx={{ maxHeight: '70vh' }}>
        <Table stickyHeader aria-busy={loading || fetching ? 'true' : 'false'}>
          {caption && (
            <caption style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>{caption}</caption>
          )}
          <TableHead>
            <TableRow>
              {selectable && (
                <TableCell padding="checkbox">
                  <Checkbox
                    indeterminate={someSelected && !allSelected}
                    checked={allSelected}
                    onChange={() => onSelectedChange?.(allSelected ? selected.filter((s) => !ids.includes(s)) : Array.from(new Set([...selected, ...ids])))}
                    slotProps={{ input: { 'aria-label': 'Selecionar todos da página' } }}
                  />
                </TableCell>
              )}
              {columns.map((c) => (
                <TableCell
                  key={c.key}
                  scope="col"
                  align={c.align}
                  sx={{ width: c.width, ...hideSx(c) }}
                  sortDirection={c.sortKey && sortField === c.sortKey ? sortDir : false}
                >
                  {c.sortKey && onSortChange ? (
                    <TableSortLabel
                      active={sortField === c.sortKey}
                      direction={sortField === c.sortKey ? sortDir : 'asc'}
                      onClick={() => onSortChange(sortField === c.sortKey && sortDir === 'asc' ? `-${c.sortKey}` : c.sortKey!)}
                    >
                      {c.header}
                    </TableSortLabel>
                  ) : (
                    c.header
                  )}
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {loading &&
              Array.from({ length: 10 }, (_, i) => (
                <TableRow key={`sk-${i}`}>
                  <TableCell colSpan={colSpan}>
                    <Skeleton variant="text" />
                  </TableCell>
                </TableRow>
              ))}
            {!loading && rows && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={colSpan}>
                  {filtered ? (
                    <EmptyState
                      title="Nenhum resultado para os filtros"
                      action={
                        onClearFilters ? (
                          <Box component="button" type="button" onClick={onClearFilters} sx={{ border: 0, background: 'none', color: 'primary.main', cursor: 'pointer', font: 'inherit', fontWeight: 600 }}>
                            Limpar filtros
                          </Box>
                        ) : undefined
                      }
                    />
                  ) : (
                    <EmptyState title={emptyTitle} action={emptyAction} />
                  )}
                </TableCell>
              </TableRow>
            )}
            {!loading &&
              rows?.map((row) => {
                const id = rowKey(row);
                const isSel = selected.includes(id);
                return (
                  <TableRow
                    key={id}
                    hover
                    selected={isSel}
                    onClick={onRowClick ? (e) => !(e.target as HTMLElement).closest('button, a, input, label, [role="button"], [role="checkbox"]') && onRowClick(row) : undefined}
                    sx={{ cursor: onRowClick ? 'pointer' : undefined }}
                  >
                    {selectable && (
                      <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                        <Checkbox
                          checked={isSel}
                          onChange={() => onSelectedChange?.(isSel ? selected.filter((s) => s !== id) : [...selected, id])}
                          slotProps={{ input: { 'aria-label': `Selecionar linha ${id}` } }}
                        />
                      </TableCell>
                    )}
                    {columns.map((c) => (
                      <TableCell key={c.key} align={c.align} className={c.align === 'right' ? 'num' : undefined} sx={hideSx(c)}>
                        {c.render(row)}
                      </TableCell>
                    ))}
                  </TableRow>
                );
              })}
          </TableBody>
        </Table>
      </TableContainer>
      {meta && onPageChange && (
        <Stack direction="row" sx={{ justifyContent: 'flex-end' }}>
          <TablePagination
            component="div"
            count={meta.total}
            page={Math.max(0, meta.current_page - 1)}
            rowsPerPage={meta.per_page}
            rowsPerPageOptions={[10, 25, 50, 100]}
            onPageChange={(_, p) => onPageChange(p + 1)}
            onRowsPerPageChange={(e) => onPerPageChange?.(Number(e.target.value))}
            labelRowsPerPage="Linhas por página"
            labelDisplayedRows={({ from, to, count }) => `${from}–${to} de ${new Intl.NumberFormat('pt-BR').format(count)}`}
            getItemAriaLabel={(type) => (type === 'next' ? 'Próxima página' : type === 'previous' ? 'Página anterior' : type === 'first' ? 'Primeira página' : 'Última página')}
          />
        </Stack>
      )}
    </Paper>
  );
}
