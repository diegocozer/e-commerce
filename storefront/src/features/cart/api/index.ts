import { deleteData, getData, http, patchData, postData, putData } from '@/shared/api/client';
import { setCartToken } from '@/shared/api/cartToken';
import type { Cart, ShippingQuote } from '@/shared/api/types';
import type { LineBody } from '@/shared/saleUnit/configuration';

export interface ShippingSelection {
  quoteId: string;
  optionId: string;
}

export const cartKeys = {
  all: ['cart'] as const,
  withSelection: (s: ShippingSelection) => ['cart', s] as const,
  shippingQuote: (key: string) => ['cart', 'shipping-quote', key] as const,
};

/** Guarda o token quando o carrinho de visitante é criado (também vem no header). */
function remember(cart: Cart): Cart {
  if (cart.owner === 'guest' && cart.token) setCartToken(cart.token);
  return cart;
}

export async function fetchCart(selection?: ShippingSelection | null): Promise<Cart> {
  const params = selection ? { shipping_quote_id: selection.quoteId, shipping_option_id: selection.optionId } : undefined;
  return remember(await getData<Cart>('/cart', { params }));
}

export type AddItemBody = { variant_id: number } & LineBody;

export async function addCartItem(body: AddItemBody): Promise<{ cart: Cart; created: boolean }> {
  
  const res = await http.post<{ data: Cart }>('/cart/items', body);
  return { cart: remember(res.data.data), created: res.status === 201 };
}

export async function updateCartItem(id: number, body: LineBody | Partial<{ width_m: number; height_m: number; pieces: number }>): Promise<Cart> {
  return remember(await patchData<Cart>(`/cart/items/${id}`, body));
}

export async function removeCartItem(id: number): Promise<Cart> {
  return remember(await deleteData<Cart>(`/cart/items/${id}`));
}

export async function clearCart(): Promise<Cart> {
  return remember(await deleteData<Cart>('/cart'));
}

export async function applyCoupon(code: string): Promise<Cart> {
  return remember(await putData<Cart>('/cart/coupon', { code: code.trim().toUpperCase() }));
}

export async function removeCoupon(): Promise<Cart> {
  return remember(await deleteData<Cart>('/cart/coupon'));
}

export function quoteCartShipping(input: { postal_code: string } | { address_uuid: string }): Promise<ShippingQuote> {
  return postData<ShippingQuote>('/cart/shipping-quote', input);
}

export async function acknowledgePrices(): Promise<Cart> {
  return remember(await postData<Cart>('/cart/acknowledge-prices'));
}
