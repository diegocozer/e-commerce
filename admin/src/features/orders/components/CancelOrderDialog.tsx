import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Stack } from '@mui/material';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminOrder } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { notify } from '@/shared/ui';
import { RHFCheckbox, RHFSelect, RHFTextField } from '@/shared/ui/form';
import { useCancelOrder } from '../api';
import { cancelSchema, CANCEL_REASONS, composeReason, type CancelForm } from '../schemas/transition';

export function CancelOrderDialog({ order, onClose }: { order: AdminOrder; onClose: () => void }) {
  const refund = order.cancel_requires_refund;
  const schema = useMemo(() => cancelSchema(refund), [refund]);
  const m = useCancelOrder(order.id);
  const [err, setErr] = useState<string | null>(null);
  const { control, handleSubmit, watch } = useForm<CancelForm>({ resolver: zodResolver(schema), defaultValues: { reason_code: '', reason_text: '', confirm_refund: false } });
  const reasonCode = watch('reason_code');
  const onSubmit = handleSubmit(async (v) => {
    const reason = composeReason(v);
    if (reason.length < 3) return setErr('Descreva o motivo (mínimo 3 caracteres).');
    setErr(null);
    try {
      await m.mutateAsync(refund ? { reason, confirm_refund: true } : { reason });
      notify.success(refund ? 'Pedido cancelado. Estorno solicitado.' : 'Pedido cancelado');
      onClose();
    } catch (e) {
      if (isApiError(e) && e.code === 'invalid_status_transition') {
        notify.error('Este pedido foi atualizado por outra pessoa. Dados recarregados.');
        onClose();
      } else setErr(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e));
    }
  });
  return (
    <Dialog open onClose={m.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="cancel-title">
      <form noValidate onSubmit={onSubmit}>
        <DialogTitle id="cancel-title">Cancelar o pedido {order.number}?</DialogTitle>
        <DialogContent>
          <Stack spacing={4} sx={{ pt: 1 }}>
            {refund ? (
              <Alert severity="warning">
                O valor de <strong>{formatBRL(order.totals.total_cents)}</strong> será estornado via PIX ao cliente e o estoque devolvido. O cupom não é devolvido.
              </Alert>
            ) : (
              <Alert severity="info">A reserva de estoque será liberada.</Alert>
            )}
            {err && <Alert severity="error" role="alert">{err}</Alert>}
            <RHFSelect control={control} name="reason_code" label="Motivo" required options={CANCEL_REASONS.map((r) => ({ value: r, label: r }))} />
            <RHFTextField control={control} name="reason_text" label={reasonCode === 'Outro' ? 'Descreva o motivo' : 'Detalhes (opcional)'} required={reasonCode === 'Outro'} multiline minRows={2} maxLength={500} />
            {refund && <RHFCheckbox control={control} name="confirm_refund" label="Confirmo o estorno" required />}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} autoFocus disabled={m.isPending}>
            Voltar
          </Button>
          <Button type="submit" color="error" variant="contained" disabled={m.isPending} startIcon={m.isPending ? <CircularProgress size={14} color="inherit" /> : undefined}>
            {refund ? 'Cancelar e estornar' : 'Cancelar pedido'}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
