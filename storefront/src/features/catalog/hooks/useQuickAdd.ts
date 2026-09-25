import { useState } from 'react';
import type { ProductCard } from '@/shared/api/types';
import { formatQuantity } from '@/shared/formatters/quantity';
import { useAddToCartFlow } from '@/features/cart/hooks/useAddToCartFlow';

/** "Adicionar" no card: só UNIT/ROLL/BOX com quick_add (1 variante, min 1). */
export function useQuickAdd() {
  const { addToCart } = useAddToCartFlow();
  const [pendingId, setPending] = useState<number | null>(null);
  const quickAdd = async (p: ProductCard) => {
    setPending(p.id);
    try {
      await addToCart({ variant_id: p.default_variant.id, quantity: 1 }, { productName: p.name, configurationLabel: formatQuantity(1, p.sale_unit), unit: p.sale_unit });
    } finally {
      setPending(null);
    }
  };
  return { quickAdd, pendingId };
}
