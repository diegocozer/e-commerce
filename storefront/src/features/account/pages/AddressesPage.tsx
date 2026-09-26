import LocationOnOutlined from '@mui/icons-material/LocationOnOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Grid from '@mui/material/Grid';
import Skeleton from '@mui/material/Skeleton';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useAuth } from '@/features/auth';
import { describeError } from '@/shared/api/errors';
import type { Address, AddressInput } from '@/shared/api/types';
import { formatPhone } from '@/shared/formatters/phone';
import { ConfirmDialog } from '@/shared/ui/ConfirmDialog';
import { EmptyState } from '@/shared/ui/EmptyState';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { accountKeys, createAddress, deleteAddress, setDefaultAddress, updateAddress } from '../api';
import { AddressForm } from '../components/AddressForm';
import { useAddresses } from '../hooks/queries';

export default function AddressesPage() {
  const q = useAddresses();
  const qc = useQueryClient();
  const notify = useSnackbar();
  const { customer } = useAuth();
  const theme = useTheme();
  const mobile = useMediaQuery(theme.breakpoints.down('sm'));
  const [editing, setEditing] = useState<Address | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Address | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: accountKeys.addresses });

  const setDefault = useMutation({
    mutationFn: (uuid: string) => setDefaultAddress(uuid),
    onMutate: async (uuid) => {
      // Otimista com rollback (UX §6.9 permite para "definir padrão").
      await qc.cancelQueries({ queryKey: accountKeys.addresses });
      const prev = qc.getQueryData<Address[]>(accountKeys.addresses);
      qc.setQueryData<Address[]>(accountKeys.addresses, (list) => list?.map((a) => ({ ...a, is_default: a.uuid === uuid })));
      return { prev };
    },
    onError: (err, _v, ctx) => {
      if (ctx?.prev) qc.setQueryData(accountKeys.addresses, ctx.prev);
      notify(describeError(err), { severity: 'error' });
    },
    onSettled: () => void refresh(),
  });
  const remove = useMutation({
    mutationFn: (uuid: string) => deleteAddress(uuid),
    onSuccess: () => {
      notify('Endereço excluído', { severity: 'success' });
      setDeleting(null);
      void refresh();
    },
    onError: (err) => notify(describeError(err), { severity: 'error' }),
  });

  const save = async (input: AddressInput) => {
    if (editing === 'new') await createAddress(input);
    else if (editing) await updateAddress(editing.uuid, input);
    notify('Endereço salvo', { severity: 'success' });
    setEditing(null);
    await refresh();
  };

  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 2 }}>
        <PageHeading>Endereços</PageHeading>
        {q.data?.length ? <Button onClick={() => setEditing('new')} disabled={q.data.length >= 10}>Adicionar endereço</Button> : null}
      </Box>
      {q.isLoading ? (
        <Skeleton variant="rectangular" height={140} />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : !q.data?.length ? (
        <EmptyState icon={<LocationOnOutlined />} title="Nenhum endereço salvo." action={<Button onClick={() => setEditing('new')}>Adicionar endereço</Button>} />
      ) : (
        <Grid container spacing={3}>
          {q.data.map((a) => (
            <Grid key={a.uuid} size={{ xs: 12, sm: 6 }}>
              <Card sx={{ p: 4, height: '100%', display: 'flex', flexDirection: 'column', gap: 1 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography sx={{ fontWeight: 700 }}>{a.label ?? 'Endereço'}</Typography>
                  {a.is_default ? <Chip size="small" color="primary" label="Padrão" /> : null}
                </Box>
                <Typography variant="body2">{a.recipient_name}</Typography>
                <Typography variant="body2">{a.formatted}</Typography>
                {a.phone ? <Typography variant="body2" color="text.secondary">{formatPhone(a.phone)}</Typography> : null}
                <Stack direction="row" spacing={1} sx={{ mt: 'auto', pt: 2, flexWrap: 'wrap' }}>
                  <Button size="small" variant="text" onClick={() => setEditing(a)} aria-label={`Editar endereço ${a.label ?? a.formatted}`}>Editar</Button>
                  {!a.is_default ? <Button size="small" variant="text" onClick={() => setDefault.mutate(a.uuid)}>Definir como padrão</Button> : null}
                  <Button size="small" variant="text" color="error" onClick={() => setDeleting(a)} aria-label={`Excluir endereço ${a.label ?? a.formatted}`}>Excluir</Button>
                </Stack>
              </Card>
            </Grid>
          ))}
        </Grid>
      )}
      <Dialog open={editing !== null} onClose={(_, r) => r !== 'backdropClick' && setEditing(null)} fullScreen={mobile} fullWidth maxWidth="md" aria-labelledby="addr-dialog">
        <DialogTitle id="addr-dialog">{editing === 'new' ? 'Novo endereço' : 'Editar endereço'}</DialogTitle>
        <DialogContent>
          {editing !== null ? (
            <AddressForm
              initial={editing === 'new' ? null : editing}
              defaults={{ recipient_name: customer?.name, phone: customer?.phone }}
              onCancel={() => setEditing(null)}
              onSubmit={save}
            />
          ) : null}
        </DialogContent>
      </Dialog>
      <ConfirmDialog
        open={Boolean(deleting)}
        title={`Excluir o endereço '${deleting?.label ?? 'Endereço'}'?`}
        confirmLabel="Excluir"
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.uuid)}
        onClose={() => setDeleting(null)}
      >
        Pedidos já feitos não são afetados.{deleting?.is_default ? ' Outro endereço será definido como padrão.' : ''}
      </ConfirmDialog>
    </Box>
  );
}
