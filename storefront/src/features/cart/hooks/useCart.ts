import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import type { Cart } from '@/shared/api/types';
import {
  acknowledgePrices,
  addCartItem,
  applyCoupon,
  cartKeys,
  clearCart,
  fetchCart,
  removeCartItem,
  removeCoupon,
  updateCartItem,
  type AddItemBody,
  type ShippingSelection,
} from '../api';
import type { LineBody } from '@/shared/saleUnit/configuration';

export function useCart(selection?: ShippingSelection | null) {
  return useQuery({
    queryKey: selection ? cartKeys.withSelection(selection) : cartKeys.all,
    queryFn: () => fetchCart(selection),
    staleTime: 0,
  });
}

/** Mutations respondem o carrinho inteiro: setQueryData(['cart']) + invalida seleção de frete e cotações. */
export function storeCart(qc: QueryClient, cart: Cart) {
  qc.setQueryData(cartKeys.all, cart);
  qc.removeQueries({ queryKey: ['cart', 'shipping-quote'] });
  qc.invalidateQueries({
    predicate: (q) => q.queryKey[0] === 'cart' && q.queryKey.length === 2 && typeof q.queryKey[1] === 'object',
  });
}

export function useAddToCart() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: AddItemBody) => addCartItem(body),
    onSuccess: ({ cart }) => storeCart(qc, cart),
  });
}

export function useCartMutations() {
  const qc = useQueryClient();
  const onSuccess = (cart: Cart) => storeCart(qc, cart);
  return {
    update: useMutation({ mutationFn: ({ id, body }: { id: number; body: LineBody | Partial<{ width_m: number; height_m: number; pieces: number }> }) => updateCartItem(id, body), onSuccess }),
    remove: useMutation({ mutationFn: (id: number) => removeCartItem(id), onSuccess }),
    clear: useMutation({ mutationFn: clearCart, onSuccess }),
    applyCoupon: useMutation({ mutationFn: (code: string) => applyCoupon(code), onSuccess }),
    removeCoupon: useMutation({ mutationFn: removeCoupon, onSuccess }),
    acknowledge: useMutation({ mutationFn: acknowledgePrices, onSuccess }),
  };
}
