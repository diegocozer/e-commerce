import { Button, Card, CardContent, Checkbox, FormControlLabel, Grid, Link, List, ListItem, ListItemText, Stack, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminCompany } from '@/shared/api/types';
import { Can } from '@/shared/auth';
import { ErrorState, KeyValue, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { useCompany, usePatchCompany } from '../api';
import { CustomerPricesTable } from '../components/CustomerPricesTable';
import { PriceListAssign } from '../components/PriceListAssign';

function EditCompany({ c }: { c: AdminCompany }) {
  const patch = usePatchCompany(c.id);
  const [v, setV] = useState({ legal_name: c.legal_name, trade_name: c.trade_name ?? '', state_registration: c.state_registration ?? '', state_registration_exempt: c.state_registration_exempt });
  return (
    <Can perm="customers.update">
      <Stack spacing={3}>
        <TextField label="Razão social" value={v.legal_name} onChange={(e) => setV({ ...v, legal_name: e.target.value })} required />
        <TextField label="Nome fantasia" value={v.trade_name} onChange={(e) => setV({ ...v, trade_name: e.target.value })} />
        <TextField label="Inscrição estadual" value={v.state_registration} disabled={v.state_registration_exempt} onChange={(e) => setV({ ...v, state_registration: e.target.value })} />
        <FormControlLabel control={<Checkbox checked={v.state_registration_exempt} onChange={(e) => setV({ ...v, state_registration_exempt: e.target.checked })} />} label="Isento de IE" />
        <Button
          variant="contained"
          sx={{ alignSelf: 'flex-start' }}
          disabled={patch.isPending}
          onClick={() =>
            patch.mutate(
              { ...v, trade_name: v.trade_name || null, state_registration: v.state_registration_exempt ? null : v.state_registration || null },
              { onSuccess: () => notify.success('Empresa salva'), onError: (e) => notify.error(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e)) },
            )
          }
        >
          Salvar empresa
        </Button>
      </Stack>
    </Can>
  );
}

export default function CompanyDetailPage() {
  const id = Number(useParams().id);
  const q = useCompany(id);
  const patch = usePatchCompany(id);
  useBreadcrumb(q.data?.legal_name);
  if (q.isPending) return <LoadingBlock />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const c = q.data;
  return (
    <>
      <PageHeader title={c.trade_name ?? c.legal_name} back={{ to: '/empresas', label: 'Empresas' }} />
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card>
            <CardContent>
              <KeyValue
                items={[
                  { label: 'CNPJ', value: <span className="num">{c.cnpj_masked}</span> },
                  { label: 'Razão social', value: c.legal_name },
                  { label: 'IE', value: c.state_registration_exempt ? 'Isento' : (c.state_registration ?? '—') },
                  { label: 'Tabela de preço', value: c.price_list?.name ?? 'Padrão (varejo)' },
                ]}
              />
              <PriceListAssign currentId={c.price_list?.id ?? null} pending={patch.isPending} onSave={(plId) => patch.mutateAsync({ price_list_id: plId })} />
              <Typography variant="h4" component="h2" sx={{ mt: 4 }}>
                Usuários vinculados
              </Typography>
              <List dense>
                {c.customers.map((u) => (
                  <ListItem key={u.id} disableGutters>
                    <ListItemText primary={<Link component={RouterLink} to={`/clientes/${u.id}`}>{u.name}</Link>} secondary={u.email} />
                  </ListItem>
                ))}
              </List>
            </CardContent>
          </Card>
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <Card>
            <CardContent>
              <EditCompany key={c.updated_at} c={c} />
            </CardContent>
          </Card>
        </Grid>
        <Grid size={12}>
          <Card>
            <CardContent>
              <Typography variant="h3" component="h2" sx={{ mb: 2 }}>
                Preços específicos
              </Typography>
              <CustomerPricesTable filter={{ company_id: c.id }} />
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </>
  );
}
