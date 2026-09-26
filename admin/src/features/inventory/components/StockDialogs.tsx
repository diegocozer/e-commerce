import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, Checkbox, FormControlLabel, Typography } from '@mui/material';
import { useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { isApiError } from '@/shared/api/errors';
import type { InventoryItem } from '@/shared/api/types';
import { formatStock, toMilli } from '@/shared/formatters/quantity';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { notify } from '@/shared/ui';
import { FormDialog, RHFQuantityField, RHFSelect, RHFTextField } from '@/shared/ui/form';
import { useLowStockThreshold, useStockAdjust, useStockEntry } from '../api';
import { adjustSchema, ADJUST_REASONS, composeReason, entrySchema, ENTRY_REASONS, type AdjustForm, type EntryForm } from '../schemas';

const intUnit = (i: InventoryItem) => ['UNIT', 'ROLL', 'BOX'].includes(i.sale_unit);

export function EntryDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const schema = useMemo(() => entrySchema(item.sale_unit), [item.sale_unit]);
  const m = useStockEntry(item.variant_id);
  const { control, handleSubmit, setError } = useForm<EntryForm>({ resolver: zodResolver(schema), defaultValues: { quantity: '', reason_code: '', note: '' } });
  const code = useWatch({ control, name: 'reason_code' });
  const { error, run } = useFormSubmit(setError, { reason: 'note' });
  const onSubmit = handleSubmit(async (v) => {
    const ok = await run(() => m.mutateAsync({ quantity: Number(v.quantity), reason: composeReason(v.reason_code, v.note) }));
    if (ok) {
      notify.success(`Entrada registrada: ${formatStock(Number(v.quantity), item.stock_unit_abbr)}`);
      onClose();
    }
  });
  return (
    <FormDialog open title={`Entrada de estoque — ${item.sku}`} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={m.isPending} error={error} submitLabel="Registrar entrada">
      <Typography variant="body2">
        {item.product.name} · {item.variant_name} · em mãos {formatStock(item.on_hand, item.stock_unit_abbr)}
      </Typography>
      <RHFQuantityField control={control} name="quantity" label="Quantidade" unit={item.stock_unit_abbr} decimals={intUnit(item) ? 0 : 3} required />
      <RHFSelect control={control} name="reason_code" label="Motivo" required options={ENTRY_REASONS.map((r) => ({ value: r, label: r }))} />
      <RHFTextField control={control} name="note" label={code === 'Outro' ? 'Descreva o motivo' : 'Observação / NF'} required={code === 'Outro'} placeholder="Ex.: NF 4521" />
    </FormDialog>
  );
}

export function AdjustDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const schema = useMemo(() => adjustSchema(item, item.stock_unit_abbr), [item]);
  const m = useStockAdjust(item.variant_id);
  const { control, handleSubmit, setError } = useForm<AdjustForm>({ resolver: zodResolver(schema), defaultValues: { new_on_hand: '', reason_code: '', note: '' } });
  const [newVal, code] = useWatch({ control, name: ['new_on_hand', 'reason_code'] });
  const [ack, setAck] = useState(false);
  const [ackError, setAckError] = useState(false);
  const { error, run } = useFormSubmit(setError, { reason: 'note' });
  const qc = useQueryClient();
  const milli = newVal ? toMilli(newVal) : null;
  const diff = milli === null ? null : (milli - Math.round(item.on_hand * 1000)) / 1000;
  const big = diff !== null && item.on_hand > 0 && Math.abs(diff) / item.on_hand > 0.1;

  const onSubmit = handleSubmit(async (v) => {
    if (big && !ack) return setAckError(true);
    const ok = await run(async () => {
      try {
        await m.mutateAsync({ new_on_hand: Number(v.new_on_hand), reason: composeReason(v.reason_code, v.note), expected_on_hand: item.on_hand });
      } catch (e) {
        if (isApiError(e) && e.code === 'stale_resource') {
          // 409: estoque mudou — recarrega a lista e fecha para reabrir com valores novos (UX §5.7).
          void qc.invalidateQueries({ queryKey: ['admin', 'inventory'] });
          notify.warning('O estoque mudou enquanto você editava. Valores atualizados.');
          onClose();
          return;
        }
        throw e;
      }
      notify.success('Ajuste registrado');
      onClose();
    });
    void ok;
  });

  return (
    <FormDialog open title={`Ajustar estoque — ${item.sku}`} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={m.isPending} error={error} submitLabel="Registrar ajuste">
      <Typography variant="body2">
        {item.product.name} · {item.variant_name}
        <br />
        Em mãos atual: <strong className="num">{formatStock(item.on_hand, item.stock_unit_abbr)}</strong> · Reservado: <span className="num">{formatStock(item.reserved, item.stock_unit_abbr)}</span>
      </Typography>
      <RHFQuantityField control={control} name="new_on_hand" label="Novo valor em mãos" unit={item.stock_unit_abbr} decimals={intUnit(item) ? 0 : 3} required />
      {diff !== null && diff !== 0 && (
        <Typography variant="body2" className="num" role="status">
          Diferença: {diff > 0 ? '+' : '−'}
          {formatStock(Math.abs(diff), item.stock_unit_abbr)}
        </Typography>
      )}
      <RHFSelect control={control} name="reason_code" label="Motivo" required options={ADJUST_REASONS.map((r) => ({ value: r, label: r }))} />
      <RHFTextField control={control} name="note" label={code === 'Outro' ? 'Descreva o motivo' : 'Observação'} required={code === 'Outro'} />
      {big && (
        <Alert severity="warning">
          Diferença acima de 10% do estoque atual.
          <FormControlLabel
            control={<Checkbox checked={ack} onChange={(e) => { setAck(e.target.checked); setAckError(false); }} />}
            label="Confirmo que conferi a contagem"
          />
          {ackError && <Typography variant="caption" color="error" component="div">Confirme para continuar.</Typography>}
        </Alert>
      )}
    </FormDialog>
  );
}

export function ThresholdDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const m = useLowStockThreshold(item.variant_id);
  const [value, setValue] = useState(item.low_stock_threshold_override === null ? '' : String(item.low_stock_threshold_override));
  const { control, handleSubmit } = useForm<{ threshold: string }>({ defaultValues: { threshold: value } });
  const onSubmit = handleSubmit(async (v) => {
    setValue(v.threshold);
    try {
      await m.mutateAsync({ low_stock_threshold: v.threshold === '' ? null : Number(v.threshold) });
      notify.success('Mínimo atualizado');
      onClose();
    } catch {
      notify.error('Não foi possível salvar o mínimo.');
    }
  });
  return (
    <FormDialog open title={`Estoque mínimo — ${item.sku}`} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={m.isPending} maxWidth="xs">
      <RHFQuantityField control={control} name="threshold" label="Mínimo (vazio = padrão da loja)" unit={item.stock_unit_abbr} />
    </FormDialog>
  );
}
