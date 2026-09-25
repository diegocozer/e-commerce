import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useNavigate } from 'react-router';
import { describeError, toApiError } from '@/shared/api/errors';
import type { SaleUnit, StockIssue } from '@/shared/api/types';
import { formatQuantity } from '@/shared/formatters/quantity';
import { useSnackbar } from '@/shared/ui/Snackbar';
import type { AddItemBody } from '../api';
import { useAddToCart } from './useCart';
import { useMiniCart } from './useMiniCart';

/** Adicionar ao carrinho com feedback (UX §4.4.7): snackbar + mini-carrinho no desktop. Não otimista. */
export function useAddToCartFlow() {
  const add = useAddToCart();
  const notify = useSnackbar();
  const mini = useMiniCart();
  const navigate = useNavigate();
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up('md'));

  async function addToCart(body: AddItemBody, meta: { productName: string; configurationLabel: string; unit: SaleUnit }) {
    try {
      const { cart, created } = await add.mutateAsync(body);
      const item = [...cart.items].reverse().find((i) => i.variant_id === body.variant_id);
      notify(created ? `${meta.productName} — ${meta.configurationLabel} adicionado ao carrinho` : 'Quantidade atualizada no carrinho', {
        severity: 'success',
        actionLabel: 'Ver carrinho',
        onAction: () => navigate('/carrinho'),
      });
      if (desktop) mini.show(item?.id ?? null);
      return { ok: true as const, cart };
    } catch (err) {
      const e = toApiError(err);
      if (e.code === 'insufficient_stock') {
        const issue = e.extra<StockIssue[]>('items')?.[0];
        const msg = issue ? `Estoque insuficiente. Disponível: ${formatQuantity(issue.available_quantity, meta.unit)}.` : e.message;
        notify(msg, { severity: 'error' });
        return { ok: false as const, error: e, availableQuantity: issue?.available_quantity ?? null };
      }
      if (!e.isValidation) {
        notify(describeError(e), { severity: 'error', actionLabel: 'Tentar novamente', onAction: () => void addToCart(body, meta) });
      }
      return { ok: false as const, error: e, availableQuantity: null };
    }
  }

  return { addToCart, isPending: add.isPending };
}
