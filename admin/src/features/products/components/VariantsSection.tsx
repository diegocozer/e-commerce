import AddIcon from '@mui/icons-material/AddOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import ExpandLessIcon from '@mui/icons-material/ExpandLessOutlined';
import ExpandMoreIcon from '@mui/icons-material/ExpandMoreOutlined';
import { Button, Collapse, Grid, IconButton, Link, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tooltip } from '@mui/material';
import { useQueryClient } from '@tanstack/react-query';
import { Fragment, useState } from 'react';
import { useFieldArray, useWatch, type Control, type UseFormGetValues, type UseFormSetError, type UseFormSetValue } from 'react-hook-form';
import { Link as RouterLink } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import type { AdminVariant, SaleUnit } from '@/shared/api/types';
import { formatStock, SALE_UNIT_ABBR } from '@/shared/formatters/quantity';
import { ConfirmDialog, notify } from '@/shared/ui';
import { FormSection, RHFDateTimeField, RHFIntField, RHFMoneyField, RHFQuantityField, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { checkSku, deleteVariant, productKeys } from '../api';
import { emptyVariant, WEIGHT_UNIT_LABEL, type ProductForm } from '../schemas/product';

interface Props {
  control: Control<ProductForm>;
  setError: UseFormSetError<ProductForm>;
  setValue: UseFormSetValue<ProductForm>;
  getValues: UseFormGetValues<ProductForm>;
  productId: number | null;
  existing: AdminVariant[];
  canPrices: boolean;
  canMoveStock: boolean;
  readOnly: boolean;
}

/** Editor de variantes (upsert): linhas com id atualizam; sem id criam (API A-05). */
export function VariantsSection({ control, setError, setValue, getValues, productId, existing, canPrices, canMoveStock, readOnly }: Props) {
  const qc = useQueryClient();
  const { fields, append, remove } = useFieldArray({ control, name: 'variants', keyName: 'key' });
  const unit = useWatch({ control, name: 'sale_unit' }) as SaleUnit;
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const [toDelete, setToDelete] = useState<{ index: number; id: number } | null>(null);
  const [deleting, setDeleting] = useState(false);
  const priceDisabled = readOnly || !canPrices;

  const onSkuBlur = async (index: number) => {
    const v = getValues(`variants.${index}`);
    const sku = v.sku.trim().toUpperCase();
    if (!sku) return;
    setValue(`variants.${index}.sku`, sku);
    try {
      const r = await checkSku(sku, v.id);
      if (!r.available) setError(`variants.${index}.sku`, { type: 'server', message: `SKU já usado em '${r.used_by?.product_name ?? 'outro produto'}'.` });
    } catch {
      /* validado de novo no salvar */
    }
  };

  const applyToAll = (field: 'price_cents' | 'weight_grams') => {
    const first = getValues(`variants.0.${field}`);
    fields.forEach((_, i) => i > 0 && setValue(`variants.${i}.${field}`, first, { shouldDirty: true }));
  };

  const confirmDelete = async () => {
    if (!toDelete || productId === null) return;
    setDeleting(true);
    try {
      await deleteVariant(productId, toDelete.id);
      remove(toDelete.index);
      notify.success('Variante excluída');
      void qc.invalidateQueries({ queryKey: productKeys.detail(productId) });
    } catch (e) {
      notify.error(errorMessage(e));
    } finally {
      setDeleting(false);
      setToDelete(null);
    }
  };

  return (
    <FormSection
      id="variantes"
      title={`Variantes (${fields.length})`}
      description={!canPrices ? 'Preços somente leitura: você não tem permissão para alterar preços.' : undefined}
      actions={
        !readOnly && fields.length > 1 ? (
          <Stack direction="row" spacing={1}>
            {canPrices && <Button size="small" onClick={() => applyToAll('price_cents')}>Preço da 1ª p/ todas</Button>}
            <Button size="small" onClick={() => applyToAll('weight_grams')}>Peso da 1ª p/ todas</Button>
          </Stack>
        ) : undefined
      }
    >
      <TableContainer>
        <Table size="small" aria-label="Variantes">
          <TableHead>
            <TableRow>
              <TableCell />
              <TableCell sx={{ minWidth: 140 }}>Variante *</TableCell>
              <TableCell sx={{ minWidth: 150 }}>SKU *</TableCell>
              <TableCell sx={{ minWidth: 140 }}>Preço * (/{SALE_UNIT_ABBR[unit]})</TableCell>
              <TableCell sx={{ minWidth: 130 }}>Promo</TableCell>
              <TableCell sx={{ minWidth: 120 }}>Peso * ({WEIGHT_UNIT_LABEL[unit]})</TableCell>
              <TableCell sx={{ minWidth: 110 }} align="right">Estoque</TableCell>
              <TableCell>Ativa</TableCell>
              <TableCell />
            </TableRow>
          </TableHead>
          <TableBody>
            {fields.map((f, i) => {
              const saved = existing.find((e) => e.id === f.id);
              const isOpen = !!open[f.key];
              return (
                <Fragment key={f.key}>
                  <TableRow sx={{ '& > td': { borderBottom: isOpen ? 'none' : undefined, verticalAlign: 'top' } }}>
                    <TableCell padding="checkbox">
                      <IconButton aria-label={isOpen ? `Ocultar detalhes da variante ${i + 1}` : `Mais campos da variante ${i + 1}`} aria-expanded={isOpen} onClick={() => setOpen((o) => ({ ...o, [f.key]: !isOpen }))}>
                        {isOpen ? <ExpandLessIcon /> : <ExpandMoreIcon />}
                      </IconButton>
                    </TableCell>
                    <TableCell>
                      <RHFTextField control={control} name={`variants.${i}.name`} label="Nome" disabled={readOnly} />
                    </TableCell>
                    <TableCell>
                      <RHFTextField control={control} name={`variants.${i}.sku`} label="SKU" disabled={readOnly} onBlurExtra={() => void onSkuBlur(i)} />
                    </TableCell>
                    <TableCell>
                      <RHFMoneyField control={control} name={`variants.${i}.price_cents`} label="Preço" disabled={priceDisabled && f.id !== null} />
                    </TableCell>
                    <TableCell>
                      <RHFMoneyField control={control} name={`variants.${i}.promo_price_cents`} label="Promo" disabled={priceDisabled} />
                    </TableCell>
                    <TableCell>
                      <RHFIntField control={control} name={`variants.${i}.weight_grams`} label="Peso" unit="g" disabled={readOnly} />
                    </TableCell>
                    <TableCell align="right">
                      {saved ? (
                        <Tooltip title="Alterações de estoque exigem movimento com motivo">
                          <Link component={RouterLink} to={`/estoque?q=${encodeURIComponent(saved.sku)}`} className="num">
                            {formatStock(saved.inventory.available, SALE_UNIT_ABBR[unit])} ↗
                          </Link>
                        </Tooltip>
                      ) : canMoveStock ? (
                        <RHFQuantityField control={control} name={`variants.${i}.initial_stock`} label="Estoque inicial" unit={SALE_UNIT_ABBR[unit]} decimals={['UNIT', 'ROLL', 'BOX'].includes(unit) ? 0 : 3} disabled={readOnly} />
                      ) : (
                        '—'
                      )}
                    </TableCell>
                    <TableCell>
                      <RHFSwitch control={control} name={`variants.${i}.is_active`} label="" disabled={readOnly} />
                    </TableCell>
                    <TableCell>
                      {!readOnly && fields.length > 1 && (
                        <IconButton
                          aria-label={`Excluir variante ${i + 1}`}
                          onClick={() => (f.id === null ? remove(i) : setToDelete({ index: i, id: f.id }))}
                        >
                          <DeleteIcon />
                        </IconButton>
                      )}
                    </TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell colSpan={9} sx={{ py: 0 }}>
                      <Collapse in={isOpen} unmountOnExit>
                        <Grid container spacing={3} sx={{ py: 3 }}>
                          <Grid size={{ xs: 12, md: 3 }}>
                            <RHFTextField control={control} name={`variants.${i}.gtin`} label="GTIN/EAN" disabled={readOnly} />
                          </Grid>
                          <Grid size={{ xs: 12, md: 9 }}>
                            <RHFTextField control={control} name={`variants.${i}.attributes`} label="Atributos" helperText='Ex.: "cor: Branco; acabamento: Brilho"' disabled={readOnly} />
                          </Grid>
                          <Grid size={{ xs: 12, md: 3 }}>
                            <RHFMoneyField control={control} name={`variants.${i}.cost_cents`} label="Custo" disabled={priceDisabled} />
                          </Grid>
                          <Grid size={{ xs: 12, md: 3 }}>
                            <RHFDateTimeField control={control} name={`variants.${i}.promo_starts_at`} label="Promo — início" disabled={priceDisabled} />
                          </Grid>
                          <Grid size={{ xs: 12, md: 3 }}>
                            <RHFDateTimeField control={control} name={`variants.${i}.promo_ends_at`} label="Promo — fim" disabled={priceDisabled} />
                          </Grid>
                          <Grid size={{ xs: 12, md: 3 }}>
                            <RHFIntField control={control} name={`variants.${i}.units_per_package`} label="Unidades por embalagem" disabled={readOnly} />
                          </Grid>
                          <Grid size={{ xs: 4, md: 2 }}>
                            <RHFQuantityField control={control} name={`variants.${i}.package_length_cm`} label="Emb. comprimento" unit="cm" decimals={1} disabled={readOnly} />
                          </Grid>
                          <Grid size={{ xs: 4, md: 2 }}>
                            <RHFQuantityField control={control} name={`variants.${i}.package_width_cm`} label="Emb. largura" unit="cm" decimals={1} disabled={readOnly} />
                          </Grid>
                          <Grid size={{ xs: 4, md: 2 }}>
                            <RHFQuantityField control={control} name={`variants.${i}.package_height_cm`} label="Emb. altura" unit="cm" decimals={1} disabled={readOnly} />
                          </Grid>
                          {unit === 'ROLL' && (
                            <Grid size={{ xs: 12, md: 3 }}>
                              <RHFQuantityField control={control} name={`variants.${i}.roll_length_m`} label="Conteúdo do rolo" unit="m" required disabled={readOnly} />
                            </Grid>
                          )}
                          {unit === 'BOX' && (
                            <Grid size={{ xs: 12, md: 3 }}>
                              <RHFIntField control={control} name={`variants.${i}.units_per_box`} label="Unidades por caixa" unit="un" required disabled={readOnly} />
                            </Grid>
                          )}
                          {(unit === 'SQUARE_METER' || unit === 'LINEAR_METER') && (
                            <Grid size={{ xs: 12, md: 3 }}>
                              <RHFQuantityField control={control} name={`variants.${i}.fixed_width_m`} label="Largura (sobrescreve o produto)" unit="m" disabled={readOnly} />
                            </Grid>
                          )}
                        </Grid>
                      </Collapse>
                    </TableCell>
                  </TableRow>
                </Fragment>
              );
            })}
          </TableBody>
        </Table>
      </TableContainer>
      {!readOnly && fields.length < 50 && (
        <Button startIcon={<AddIcon />} onClick={() => append(emptyVariant())} sx={{ mt: 2 }}>
          Adicionar variante
        </Button>
      )}
      <ConfirmDialog
        open={!!toDelete}
        title="Excluir esta variante?"
        description="A variante será desativada; pedidos antigos mantêm o histórico."
        confirmLabel="Excluir variante"
        destructive
        loading={deleting}
        onConfirm={() => void confirmDelete()}
        onClose={() => setToDelete(null)}
      />
    </FormSection>
  );
}
