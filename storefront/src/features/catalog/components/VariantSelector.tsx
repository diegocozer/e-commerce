import Box from '@mui/material/Box';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import type { ProductDetail, ProductVariant } from '@/shared/api/types';

/** Escolhe a variante que casa com os atributos desejados (preferindo manter os demais). */
export function pickVariant(product: ProductDetail, current: ProductVariant, axisKey: string, value: string): ProductVariant {
  const wanted = { ...current.attributes, [axisKey]: value };
  const exact = product.variants.find((v) => Object.entries(wanted).every(([k, val]) => v.attributes[k] === val));
  return exact ?? product.variants.find((v) => v.attributes[axisKey] === value) ?? current;
}

/** Seletor por eixo (UX §4.4.3): radiogroup com nomes visíveis; combinações sem estoque ficam riscadas. */
export function VariantSelector({ product, selected, onSelect }: { product: ProductDetail; selected: ProductVariant; onSelect: (v: ProductVariant) => void }) {
  if (product.variants.length <= 1) return null;
  const axes = product.attribute_axes.length
    ? product.attribute_axes
    : [{ key: '__name', label: 'Opção', values: product.variants.map((v) => v.name) }];
  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
      {axes.map((axis) => {
        const value = axis.key === '__name' ? selected.name : selected.attributes[axis.key];
        return (
          <Box key={axis.key}>
            <Typography id={`axis-${axis.key}`} variant="body2" sx={{ mb: 1 }}>
              {axis.label}: <strong>{value}</strong>
            </Typography>
            <ToggleButtonGroup
              exclusive
              value={value ?? null}
              aria-labelledby={`axis-${axis.key}`}
              role="radiogroup"
              onChange={(_, v: string | null) => {
                if (!v) return;
                const next = axis.key === '__name' ? product.variants.find((x) => x.name === v) : pickVariant(product, selected, axis.key, v);
                if (next) onSelect(next);
              }}
              sx={{ flexWrap: 'wrap', gap: 2, '& .MuiToggleButtonGroup-grouped': { border: 1, borderColor: 'border.input', borderRadius: '8px !important', m: 0 } }}
            >
              {axis.values.map((val) => {
                const candidate = axis.key === '__name' ? product.variants.find((x) => x.name === val) : pickVariant(product, selected, axis.key, val);
                const out = candidate?.availability.status === 'out_of_stock';
                return (
                  <ToggleButton
                    key={val}
                    value={val}
                    role="radio"
                    aria-checked={val === value}
                    aria-label={out ? `${val} (sem estoque)` : val}
                    sx={{ minHeight: 44, px: 4, textTransform: 'none', textDecoration: out ? 'line-through' : 'none', '&.Mui-selected': { bgcolor: 'primary.light', color: 'primary.main', borderColor: 'primary.main' } }}
                  >
                    {val}
                  </ToggleButton>
                );
              })}
            </ToggleButtonGroup>
          </Box>
        );
      })}
    </Box>
  );
}
