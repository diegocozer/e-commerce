import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import { useState } from 'react';
import { QuantityConfigurator, type ConfiguratorState } from '@/features/catalog/components/QuantityConfigurator';
import { describeError } from '@/shared/api/errors';
import type { CartItem, ProductVariant } from '@/shared/api/types';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { useCartMutations } from '../hooks/useCart';

/** "Editar medidas" (SQUARE_METER) — reutiliza o configurador §4.4.4c. */
export function EditDimensionsDialog({ item, onClose }: { item: CartItem; onClose: () => void }) {
  const { update } = useCartMutations();
  const notify = useSnackbar();
  const [cfg, setCfg] = useState<ConfiguratorState | null>(null);
  const variant: ProductVariant = {
    id: item.variant_id,
    sku: item.variant.sku,
    gtin: null,
    name: item.variant.name,
    attributes: item.variant.attributes,
    position: 0,
    is_default: true,
    image_ids: [],
    rules: item.rules!,
    price: {
      unit_price_cents: item.unit_price_cents ?? 0,
      base_unit_price_cents: item.base_unit_price_cents ?? 0,
      compare_at_cents: item.compare_at_cents,
      price_source: item.price_source ?? 'base',
      price_source_label: item.price_source_label,
      promotion: null,
      tiers: [],
    },
    availability: item.availability,
    weight_grams: 0,
    roll_length_m: null,
    units_per_box: null,
  };
  const save = () => {
    if (!cfg?.valid) return;
    update.mutate(
      { id: item.id, body: cfg.valid.body },
      {
        onSuccess: () => {
          notify('Medidas atualizadas', { severity: 'success' });
          onClose();
        },
        onError: (err) => notify(describeError(err), { severity: 'error' }),
      },
    );
  };
  return (
    <Dialog open onClose={onClose} fullWidth maxWidth="sm" aria-labelledby={`edit-${item.id}`}>
      <DialogTitle id={`edit-${item.id}`}>Editar medidas — {item.product.name}</DialogTitle>
      <DialogContent>
        <QuantityConfigurator productSlug={item.product.slug} productName={item.product.name} variant={variant} initialConfiguration={item.configuration} onStateChange={setCfg} />
      </DialogContent>
      <DialogActions>
        <Button variant="outlined" onClick={onClose}>
          Cancelar
        </Button>
        <Button onClick={save} disabled={!cfg?.valid || Boolean(cfg.blockedReason)} loading={update.isPending}>
          Salvar medidas
        </Button>
      </DialogActions>
    </Dialog>
  );
}
