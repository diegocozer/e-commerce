import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField } from '@mui/material';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminOrder, AdminTransition } from '@/shared/api/types';
import { ORDER_STATUS } from '@/shared/formatters/labels';
import { notify } from '@/shared/ui';
import { applyServerErrors, RHFTextField } from '@/shared/ui/form';
import { useTransitionOrder, type TransitionInput } from '../api';
import { transitionSchema, type TransitionForm } from '../schemas/transition';

const SUCCESS: Partial<Record<string, string>> = {
  processing: 'Pedido marcado em separação',
  shipped: 'Pedido marcado como enviado',
  ready_for_pickup: 'Pedido pronto para retirada',
  delivered: 'Pedido marcado como entregue',
  picked_up: 'Pedido marcado como retirado',
};

interface Props {
  order: AdminOrder;
  transition: AdminTransition;
  onClose: () => void;
}

/** Dialog de transição: mostra apenas os campos da transição devolvida pela API. */
export function TransitionDialog({ order, transition, onClose }: Props) {
  const fields = new Set([...transition.required_fields, ...transition.optional_fields]);
  const required = new Set(transition.required_fields);
  const schema = useMemo(() => transitionSchema(transition), [transition]);
  const [topError, setTopError] = useState<string | null>(null);
  const [confirmNumber, setConfirmNumber] = useState('');
  const m = useTransitionOrder(order.id);
  const { control, handleSubmit, setError } = useForm<TransitionForm>({
    resolver: zodResolver(schema),
    defaultValues: { note: '', tracking_code: order.shipping.tracking_code ?? '', tracking_url: order.shipping.tracking_url ?? '', carrier_name: '', picked_up_by_name: '', picked_up_by_document: '', confirm_number: '' },
  });
  const isPickup = transition.to_status === 'picked_up';
  const numberOk = !isPickup || confirmNumber.trim().toUpperCase() === order.number.toUpperCase();

  const onSubmit = handleSubmit(async (v) => {
    setTopError(null);
    const body: TransitionInput = { to_status: transition.to_status };
    const put = (k: keyof TransitionForm & keyof TransitionInput) => {
      if (fields.has(k as never) && v[k].trim() !== '') (body as unknown as Record<string, string>)[k] = v[k].trim();
    };
    put('note');
    put('tracking_code');
    put('tracking_url');
    put('carrier_name');
    put('picked_up_by_name');
    put('picked_up_by_document');
    try {
      await m.mutateAsync(body);
      notify.success(SUCCESS[transition.to_status] ?? 'Status atualizado');
      onClose();
    } catch (e) {
      if (isApiError(e) && e.code === 'invalid_status_transition') {
        notify.error('Este pedido foi atualizado por outra pessoa. Dados recarregados.');
        onClose();
        return;
      }
      const rest = applyServerErrors(e, setError, { knownFields: (k) => fields.has(k as never) });
      if (!isApiError(e) || !e.fieldErrors) setTopError(errorMessage(e));
      else if (rest.length) setTopError(rest.join(' '));
    }
  });

  return (
    <Dialog open onClose={m.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="transition-title">
      <form noValidate onSubmit={onSubmit}>
        <DialogTitle id="transition-title">
          {transition.label} — {order.number}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={4} sx={{ pt: 1 }}>
            <Alert severity="info">
              Novo status: <strong>{ORDER_STATUS[transition.to_status].label}</strong>
              {['shipped', 'ready_for_pickup', 'delivered', 'picked_up'].includes(transition.to_status) && ' · o cliente será notificado por e-mail.'}
            </Alert>
            {topError && <Alert severity="error" role="alert">{topError}</Alert>}
            {fields.has('tracking_code') && (
              <RHFTextField control={control} name="tracking_code" label="Código de rastreio" required={required.has('tracking_code')} helperText={required.has('tracking_code') ? 'Obrigatório para transportadora.' : 'Opcional para entrega própria.'} />
            )}
            {fields.has('tracking_url') && <RHFTextField control={control} name="tracking_url" label="URL de rastreio (https)" type="url" />}
            {fields.has('carrier_name') && <RHFTextField control={control} name="carrier_name" label="Transportadora" />}
            {isPickup && (
              <TextField
                label="Confira o número do pedido"
                value={confirmNumber}
                onChange={(e) => setConfirmNumber(e.target.value)}
                required
                helperText={`Digite ${order.number} para confirmar a retirada.`}
                error={confirmNumber !== '' && !numberOk}
              />
            )}
            {fields.has('picked_up_by_name') && <RHFTextField control={control} name="picked_up_by_name" label="Nome de quem retirou" required={required.has('picked_up_by_name')} />}
            {fields.has('picked_up_by_document') && (
              <RHFTextField control={control} name="picked_up_by_document" label="Documento de quem retirou (CPF/RG)" required={required.has('picked_up_by_document')} />
            )}
            {fields.has('note') && <RHFTextField control={control} name="note" label="Observação" multiline minRows={2} maxLength={1000} helperText={transition.to_status === 'shipped' ? 'Visível ao cliente.' : 'Uso interno.'} />}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={m.isPending}>
            Voltar
          </Button>
          <Button type="submit" variant="contained" disabled={m.isPending || !numberOk} startIcon={m.isPending ? <CircularProgress size={14} color="inherit" /> : undefined}>
            {transition.label}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
}
