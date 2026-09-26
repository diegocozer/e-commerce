import { http, postData } from '@/shared/api/client';
import type { CheckoutRequest, CheckoutResult, CheckoutSummary } from '@/shared/api/types';

export interface PreviewParams {
  addressUuid: string;
  quoteId: string | null;
  optionId: string | null;
}

export const checkoutKeys = {
  preview: (p: PreviewParams) => ['checkout', 'preview', p] as const,
};

export function fetchCheckoutPreview(p: PreviewParams): Promise<CheckoutSummary> {
  return postData<CheckoutSummary>('/checkout/preview', {
    address_uuid: p.addressUuid,
    ...(p.quoteId ? { shipping_quote_id: p.quoteId, shipping_option_id: p.optionId } : {}),
    payment_method: 'pix',
  });
}

/**
 * POST /checkout — Idempotency-Key obrigatório. O corpo é a whitelist exata da API §3.E:
 * nenhum campo de preço/total/desconto/frete é enviado (ADR-012); `expected_total_cents`
 * é só comparação.
 */
export async function placeOrder(body: CheckoutRequest, idempotencyKey: string): Promise<{ result: CheckoutResult; status: number }> {
  const payload: CheckoutRequest = {
    address_uuid: body.address_uuid,
    shipping_quote_id: body.shipping_quote_id,
    shipping_option_id: body.shipping_option_id,
    payment_method: 'pix',
    expected_total_cents: body.expected_total_cents,
    notes: body.notes,
    accept_terms: true,
  };
  const res = await http.post<{ data: CheckoutResult }>('/checkout', payload, { headers: { 'Idempotency-Key': idempotencyKey }, timeout: 45000 });
  return { result: res.data.data, status: res.status };
}
