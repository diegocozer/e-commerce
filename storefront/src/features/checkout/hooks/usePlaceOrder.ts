import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { ApiError, toApiError } from '@/shared/api/errors';
import type { CheckoutRequest, CheckoutResult } from '@/shared/api/types';
import { placeOrder } from '../api';
import { idempotencyKeyFor } from './useCheckoutState';

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

export function fingerprintOf(body: CheckoutRequest): string {
  return JSON.stringify([body.address_uuid, body.shipping_quote_id, body.shipping_option_id, body.payment_method, body.expected_total_cents, body.notes ?? null]);
}

/**
 * Envia o pedido com a MESMA chave em retries (rede/timeout/503 gateway), backoff 2 s/4 s.
 */
export function usePlaceOrder(retryDelaysMs: number[] = [2000, 4000]) {
  const qc = useQueryClient();
  const [phase, setPhase] = useState<'idle' | 'sending' | 'verifying'>('idle');

  async function submit(body: CheckoutRequest): Promise<CheckoutResult> {
    const key = idempotencyKeyFor(fingerprintOf(body));
    setPhase('sending');
    let lastError: ApiError | null = null;
    try {
      for (let attempt = 0; attempt <= retryDelaysMs.length; attempt++) {
        try {
          const { result } = await placeOrder(body, key);
          qc.removeQueries({ queryKey: ['cart'] });
          qc.setQueryData(['me', 'orders', 'detail', result.order.uuid], result.order);
          return result;
        } catch (err) {
          lastError = toApiError(err);
          const retryable = lastError.isNetwork || lastError.code === 'payment_gateway_unavailable';
          if (!retryable || attempt === retryDelaysMs.length) throw lastError;
          setPhase('verifying');
          await sleep(retryDelaysMs[attempt]);
        }
      }
      throw lastError ?? new Error('unreachable');
    } finally {
      setPhase('idle');
    }
  }

  return { submit, phase, isPending: phase !== 'idle' };
}
