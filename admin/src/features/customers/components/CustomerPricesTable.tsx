import { Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import type { CustomerPrice, Paginated } from '@/shared/api/types';
import { formatDate } from '@/shared/formatters/date';
import { formatBRL } from '@/shared/formatters/money';
import { ErrorState, LoadingBlock } from '@/shared/ui';

/** Preços específicos (customer_prices) de um cliente ou empresa. */
export function CustomerPricesTable({ filter }: { filter: { customer_id?: number; company_id?: number } }) {
  const q = useQuery({ queryKey: ['admin', 'customer-prices', filter], queryFn: () => api.get<Paginated<CustomerPrice>>('/admin/customer-prices', { ...filter, per_page: 100 }) });
  if (q.isPending) return <LoadingBlock lines={2} />;
  if (q.error) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  if (q.data.data.length === 0) return <Typography variant="body2" color="text.secondary">Nenhum preço específico.</Typography>;
  return (
    <Table size="small">
      <TableHead>
        <TableRow>
          <TableCell>Variante</TableCell>
          <TableCell align="right">Preço base</TableCell>
          <TableCell align="right">Preço específico</TableCell>
          <TableCell>Vigência</TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {q.data.data.map((p) => (
          <TableRow key={p.id}>
            <TableCell>
              {p.variant.sku} — {p.variant.product.name}
            </TableCell>
            <TableCell align="right" className="num">{formatBRL(p.variant.price_cents)}</TableCell>
            <TableCell align="right" className="num">{formatBRL(p.price_cents)}</TableCell>
            <TableCell>{p.starts_at || p.ends_at ? `${formatDate(p.starts_at) ?? '—'} a ${p.ends_at ? formatDate(p.ends_at) : 'sem fim'}` : 'Sempre'}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
