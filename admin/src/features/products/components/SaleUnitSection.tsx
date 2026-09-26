import { Alert, FormControlLabel, Grid, Radio, RadioGroup, Typography } from '@mui/material';
import { Controller, useWatch, type Control } from 'react-hook-form';
import type { SaleUnit } from '@/shared/api/types';
import { SALE_UNIT_LABEL, formatDecimal } from '@/shared/formatters/quantity';
import { FormSection, RHFQuantityField, RHFSelect } from '@/shared/ui/form';
import { INTEGER_UNITS, SALE_UNITS, type ProductForm } from '../schemas/product';

const QTY_UNIT: Record<SaleUnit, string> = { UNIT: 'un', LINEAR_METER: 'm', SQUARE_METER: 'peças', ROLL: 'rolos', KG: 'kg', BOX: 'cx' };
const fmt = (v: string) => (v ? formatDecimal(Number(v), 2, 3) : '?');

/** Campos condicionais por unidade de venda (UX §5.6 item 2). */
export function SaleUnitSection({ control, locked, originalUnit, readOnly }: { control: Control<ProductForm>; locked: boolean; originalUnit: SaleUnit | null; readOnly: boolean }) {
  const [unit, widthMode, fixedW, minW, maxW, minH, maxH] = useWatch({ control, name: ['sale_unit', 'width_mode', 'fixed_width_m', 'min_width_m', 'max_width_m', 'min_height_m', 'max_height_m'] });
  const integer = INTEGER_UNITS.includes(unit);
  const qtyUnit = QTY_UNIT[unit];
  const dis = readOnly;
  return (
    <FormSection id="unidade" title="Unidade de venda">
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, md: 6 }}>
          <RHFSelect
            control={control}
            name="sale_unit"
            label="Unidade de venda"
            required
            disabled={dis || locked}
            helperText={locked ? 'Bloqueada: já existem pedidos com este produto.' : undefined}
            options={SALE_UNITS.map((u) => ({ value: u, label: SALE_UNIT_LABEL[u] }))}
          />
        </Grid>
        {originalUnit && originalUnit !== unit && (
          <Grid size={12}>
            <Alert severity="warning">Alterar a unidade afeta estoque e preços existentes. Revise variantes, faixas e estoque após salvar.</Alert>
          </Grid>
        )}
        <Grid size={{ xs: 12, sm: 4 }}>
          <RHFQuantityField control={control} name="min_quantity" label={unit === 'SQUARE_METER' ? 'Mínimo de peças' : 'Quantidade mínima'} unit={qtyUnit} decimals={integer ? 0 : 3} required disabled={dis} />
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <RHFQuantityField control={control} name="max_quantity" label={unit === 'SQUARE_METER' ? 'Máximo de peças' : 'Quantidade máxima'} unit={qtyUnit} decimals={integer ? 0 : 3} disabled={dis} helperText="Opcional" />
        </Grid>
        <Grid size={{ xs: 12, sm: 4 }}>
          <RHFQuantityField control={control} name="quantity_step" label="Passo" unit={qtyUnit} decimals={integer ? 0 : 3} required disabled={dis} helperText={unit === 'LINEAR_METER' ? 'Ex.: 0,5 m' : unit === 'KG' ? 'Ex.: 0,1 kg' : undefined} />
        </Grid>
        {unit === 'LINEAR_METER' && (
          <Grid size={{ xs: 12, sm: 4 }} data-testid="linear-fields">
            <RHFQuantityField control={control} name="fixed_width_m" label="Largura do material" unit="m" disabled={dis} helperText="Informativa (ex.: 1,22 m)" />
          </Grid>
        )}
        {unit === 'ROLL' && (
          <Grid size={12}>
            <Typography variant="body2" color="text.secondary">
              Informe o <strong>conteúdo do rolo</strong> (comprimento em metros) em cada variante.
            </Typography>
          </Grid>
        )}
        {unit === 'BOX' && (
          <Grid size={12}>
            <Typography variant="body2" color="text.secondary">
              Informe as <strong>unidades por caixa</strong> em cada variante.
            </Typography>
          </Grid>
        )}
        {unit === 'KG' && (
          <Grid size={12}>
            <Typography variant="body2" color="text.secondary">
              Quantidades em kg com até 3 casas. O peso por unidade de venda é 1000 g/kg.
            </Typography>
          </Grid>
        )}
        {unit === 'SQUARE_METER' && (
          <Grid size={12} container spacing={4} data-testid="square-fields">
            <Grid size={12}>
              <Controller
                control={control}
                name="width_mode"
                render={({ field }) => (
                  <RadioGroup row value={field.value} onChange={(e) => field.onChange(e.target.value)} aria-label="Modo de largura">
                    <FormControlLabel value="fixed" control={<Radio />} label="Largura fixa" disabled={dis} />
                    <FormControlLabel value="variable" control={<Radio />} label="Largura variável" disabled={dis} />
                  </RadioGroup>
                )}
              />
            </Grid>
            {widthMode === 'fixed' ? (
              <Grid size={{ xs: 12, sm: 4 }}>
                <RHFQuantityField control={control} name="fixed_width_m" label="Largura" unit="m" required disabled={dis} />
              </Grid>
            ) : (
              <>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <RHFQuantityField control={control} name="min_width_m" label="Largura mínima" unit="m" required disabled={dis} />
                </Grid>
                <Grid size={{ xs: 12, sm: 4 }}>
                  <RHFQuantityField control={control} name="max_width_m" label="Largura máxima" unit="m" required disabled={dis} />
                </Grid>
              </>
            )}
            <Grid size={{ xs: 12, sm: 4 }}>
              <RHFQuantityField control={control} name="min_height_m" label="Altura mínima" unit="m" required disabled={dis} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <RHFQuantityField control={control} name="max_height_m" label="Altura máxima" unit="m" required disabled={dis} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <RHFQuantityField control={control} name="min_billable_area_m2" label="Área mínima faturável (por peça)" unit="m²" disabled={dis} />
            </Grid>
            <Grid size={12}>
              <Alert severity="info" icon={false}>
                Cliente verá:{' '}
                {widthMode === 'fixed' ? `Largura ${fmt(fixedW)} m (fixa)` : `Largura entre ${fmt(minW)} e ${fmt(maxW)} m`} · Altura entre {fmt(minH)} e {fmt(maxH)} m
              </Alert>
            </Grid>
          </Grid>
        )}
      </Grid>
    </FormSection>
  );
}
