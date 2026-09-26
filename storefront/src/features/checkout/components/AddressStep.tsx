import Add from '@mui/icons-material/Add';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Radio from '@mui/material/Radio';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { AddressForm, accountKeys, useAddresses } from '@/features/account';
import { createAddress } from '@/features/account/api';
import type { Address, Customer } from '@/shared/api/types';
import { ErrorState } from '@/shared/ui/ErrorState';
import { StepHeading } from './StepHeading';

export function AddressStep({ customer, selected, onSelect, onBack, onContinue, notice }: {
  customer: Customer;
  selected: string | null;
  onSelect: (uuid: string) => void;
  onBack: () => void;
  onContinue: () => void;
  notice?: string | null;
}) {
  const q = useAddresses();
  const qc = useQueryClient();
  const [adding, setAdding] = useState(false);

  useEffect(() => {
    if (!q.data) return;
    if (q.data.length && (!selected || !q.data.some((a) => a.uuid === selected))) onSelect((q.data.find((a) => a.is_default) ?? q.data[0]).uuid);
  }, [q.data, selected, onSelect]);

  if (q.isLoading) return <Box aria-busy="true"><Skeleton height={80} /><Skeleton height={80} /></Box>;
  if (q.error) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const addresses = q.data ?? [];
  const showForm = adding || addresses.length === 0;

  return (
    <Box>
      <StepHeading>Endereço de entrega</StepHeading>
      {notice ? <Alert severity="warning" sx={{ mb: 3 }}>{notice}</Alert> : null}
      <Box component="fieldset" sx={{ border: 0, p: 0, m: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
        <legend className="visually-hidden">Endereços salvos</legend>
        {addresses.map((a: Address) => {
          const on = a.uuid === selected;
          return (
            <Box component="label" key={a.uuid} sx={{ display: 'flex', gap: 1, p: 3, borderRadius: 2, cursor: 'pointer', border: on ? 2 : 1, borderColor: on ? 'primary.main' : 'border.input', bgcolor: on ? 'primary.light' : 'background.paper' }}>
              <Radio name="address" checked={on} onChange={() => onSelect(a.uuid)} value={a.uuid} sx={{ p: 1 }} />
              <Box>
                <Typography sx={{ fontWeight: 600 }}>
                  {a.label ?? 'Endereço'}
                  {a.is_default ? ' · Padrão' : ''}
                </Typography>
                <Typography variant="body2">{a.formatted}</Typography>
                <Typography variant="caption" color="text.secondary">Recebe: {a.recipient_name}</Typography>
              </Box>
            </Box>
          );
        })}
      </Box>
      {showForm ? (
        <Box sx={{ mt: 4, p: 4, border: 1, borderColor: 'divider', borderRadius: 3, bgcolor: 'background.paper' }}>
          <Typography variant="h4" component="h3" sx={{ mb: 3 }}>Novo endereço</Typography>
          <AddressForm
            defaults={{ recipient_name: customer.name, phone: customer.phone }}
            onCancel={addresses.length ? () => setAdding(false) : undefined}
            onSubmit={async (input) => {
              const created = await createAddress(input);
              await qc.invalidateQueries({ queryKey: accountKeys.addresses });
              onSelect(created.uuid);
              setAdding(false);
            }}
          />
        </Box>
      ) : (
        <Button variant="text" startIcon={<Add />} onClick={() => setAdding(true)} sx={{ mt: 3 }}>
          Novo endereço
        </Button>
      )}
      <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 6 }}>
        <Button variant="outlined" onClick={onBack}>‹ Voltar</Button>
        <Button onClick={onContinue} disabled={!selected || showForm}>Continuar ›</Button>
      </Box>
    </Box>
  );
}
