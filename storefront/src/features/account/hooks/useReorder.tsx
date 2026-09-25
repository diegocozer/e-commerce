import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { storeCart } from '@/features/cart/hooks/useCart';
import { describeError } from '@/shared/api/errors';
import type { ReorderReport } from '@/shared/api/types';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { reorder } from '../api';

/** "Comprar novamente" (UX §4.9.3): adiciona ao carrinho e leva ao carrinho com o relatório. */
export function useReorder() {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const notify = useSnackbar();
  const [empty, setEmpty] = useState<ReorderReport | null>(null);
  const m = useMutation({
    mutationFn: (uuid: string) => reorder(uuid),
    onSuccess: (report) => {
      storeCart(qc, report.cart);
      if (report.summary.added_items + report.summary.adjusted_items > 0) navigate('/carrinho', { state: { reorder: report } });
      else setEmpty(report);
    },
    onError: (err) => notify(describeError(err), { severity: 'error' }),
  });
  const dialog = (
    <Dialog open={Boolean(empty)} onClose={() => setEmpty(null)} aria-labelledby="reorder-empty-title">
      <DialogTitle id="reorder-empty-title">Nenhum item deste pedido está disponível no momento.</DialogTitle>
      <DialogContent>
        <ul>
          {empty?.items.map((i) => (
            <li key={i.sku}>
              {i.product_name}: {i.message}
            </li>
          ))}
        </ul>
      </DialogContent>
      <DialogActions>
        <Button variant="outlined" onClick={() => { setEmpty(null); navigate('/'); }}>
          Ver produtos semelhantes
        </Button>
        <Button onClick={() => setEmpty(null)}>Fechar</Button>
      </DialogActions>
    </Dialog>
  );
  return { start: (uuid: string) => m.mutate(uuid), isPending: m.isPending, pendingUuid: m.variables, dialog };
}
