import CloseIcon from '@mui/icons-material/CloseOutlined';
import { Box, Button, Chip, Drawer, IconButton, Link, Stack, Table, TableBody, TableCell, TableHead, TableRow, TablePagination, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import type { InventoryItem } from '@/shared/api/types';
import { Can } from '@/shared/auth';
import { formatDateTime } from '@/shared/formatters/date';
import { ACTOR_TYPE, MOVEMENT_TYPE } from '@/shared/formatters/labels';
import { formatStock } from '@/shared/formatters/quantity';
import { ErrorState, LoadingBlock, notify } from '@/shared/ui';
import { exportMovementsCsv, useMovements } from '../api';

/** Histórico de movimentos (somente leitura, paginado — UX §5.7). */
export function MovementsDrawer({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const q = useMovements(item.variant_id, { page, per_page: 25 });
  const abbr = item.stock_unit_abbr;
  const signed = (n: number) => `${n > 0 ? '+' : n < 0 ? '−' : ''}${formatStock(Math.abs(n), abbr)}`;
  return (
    <Drawer anchor="right" open onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', md: 760 } } } }}>
      <Box sx={{ p: 5 }} role="region" aria-label="Histórico de movimentos">
        <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
          <div>
            <Typography variant="h2">Histórico — {item.sku}</Typography>
            <Typography variant="body2" color="text.secondary">
              {item.product.name} · {item.variant_name}
            </Typography>
          </div>
          <Stack direction="row" spacing={1}>
            <Can perm="reports.export">
              <Button size="small" onClick={() => exportMovementsCsv(item.variant_id, {}).catch((e) => notify.error(errorMessage(e)))}>
                Exportar CSV
              </Button>
            </Can>
            <IconButton aria-label="Fechar" onClick={onClose}>
              <CloseIcon />
            </IconButton>
          </Stack>
        </Stack>
        {q.isPending && <LoadingBlock />}
        {q.error && <ErrorState error={q.error} onRetry={() => void q.refetch()} />}
        {q.data && (
          <>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Data</TableCell>
                  <TableCell>Tipo</TableCell>
                  <TableCell align="right">Em mãos</TableCell>
                  <TableCell align="right">Reservado</TableCell>
                  <TableCell align="right">Saldo após</TableCell>
                  <TableCell>Pedido</TableCell>
                  <TableCell>Usuário / motivo</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {q.data.data.map((m) => (
                  <TableRow key={m.id}>
                    <TableCell className="num">{formatDateTime(m.created_at)}</TableCell>
                    <TableCell>
                      <Chip size="small" label={MOVEMENT_TYPE[m.type].label} color={MOVEMENT_TYPE[m.type].color} variant="outlined" />
                    </TableCell>
                    <TableCell align="right" className="num">{m.on_hand_delta ? signed(m.on_hand_delta) : '—'}</TableCell>
                    <TableCell align="right" className="num">{m.reserved_delta ? signed(m.reserved_delta) : '—'}</TableCell>
                    <TableCell align="right" className="num">{formatStock(m.on_hand_after, abbr)}</TableCell>
                    <TableCell>{m.reference ? <Link component={RouterLink} to={`/pedidos/${m.reference.id}`}>{m.reference.label}</Link> : '—'}</TableCell>
                    <TableCell>
                      {m.actor.name ?? ACTOR_TYPE[m.actor.type]}
                      {m.reason && (
                        <Typography variant="caption" component="div" color="text.secondary">
                          {m.reason}
                        </Typography>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {q.data.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7}>Nenhum movimento.</TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <TablePagination component="div" count={q.data.meta.total} page={page - 1} rowsPerPage={25} rowsPerPageOptions={[25]} onPageChange={(_, p) => setPage(p + 1)} labelDisplayedRows={({ from, to, count }) => `${from}–${to} de ${count}`} />
          </>
        )}
      </Box>
    </Drawer>
  );
}
