import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { storageGetJson, storageRemove, storageSetJson } from '@/shared/lib/storage';
import { cartKeys, quoteCartShipping, type ShippingSelection } from '../api';

// Escolha de frete do carrinho (vira pré-seleção no checkout — UX §4.5).
export const CART_SHIPPING_KEY = 'cv_cart_shipping';
export interface StoredCartShipping {
  postalCode: string;
  optionId: string | null;
}

export function readCartShippingChoice(): StoredCartShipping | null {
  return storageGetJson<StoredCartShipping>(CART_SHIPPING_KEY, 'session');
}

export function useCartShipping(initialPostalCode: string | null, enabled: boolean) {
  const stored = readCartShippingChoice();
  const [postalCode, setPostalCode] = useState<string | null>(stored?.postalCode ?? null);
  const [optionId, setOptionId] = useState<string | null>(stored?.optionId ?? null);

  const quote = useQuery({
    queryKey: cartKeys.shippingQuote(postalCode ?? ''),
    queryFn: () => quoteCartShipping({ postal_code: postalCode! }),
    enabled: enabled && Boolean(postalCode),
    retry: false,
    staleTime: 25 * 60_000,
  });

  useEffect(() => {
    if (postalCode) storageSetJson(CART_SHIPPING_KEY, { postalCode, optionId }, 'session');
    else storageRemove(CART_SHIPPING_KEY, 'session');
  }, [postalCode, optionId]);

  const options = quote.data?.options ?? [];
  const validOption = optionId && options.some((o) => o.option_id === optionId) ? optionId : null;
  const selection: ShippingSelection | null = quote.data?.quote_id && validOption ? { quoteId: quote.data.quote_id, optionId: validOption } : null;

  return {
    postalCode,
    request: (cep: string) => {
      setPostalCode(cep);
    },
    quote,
    optionId: validOption,
    choose: setOptionId,
    selection,
    initialPostalCode,
  };
}
