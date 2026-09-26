import { Alert, Box, Button, Card, CardContent, Grid, Link, List, ListItem, ListItemText, Stack, Tab, Tabs, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminCustomer } from '@/shared/api/types';
import { Can } from '@/shared/auth';
import { formatDate, formatDateTime } from '@/shared/formatters/date';
import { formatCNPJ, formatCPF, formatPhone } from '@/shared/formatters/document';
import { formatBRL } from '@/shared/formatters/money';
import { ActiveChip, ConfirmDialog, ErrorState, KeyValue, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { useAnonymizeCustomer, useBlockCustomer, useCustomer, useCustomerPasswordReset, usePatchCustomer, useRevealCustomerDocument, useUnblockCustomer } from '../api';
import { CustomerOrders } from '../components/CustomerOrders';
import { CustomerPricesTable } from '../components/CustomerPricesTable';
import { PriceListAssign } from '../components/PriceListAssign';

function DataTab({ c }: { c: AdminCustomer }) {
  const reveal = useRevealCustomerDocument(c.id);
  const patch = usePatchCustomer(c.id);
  const [name, setName] = useState(c.name);
  const [phone, setPhone] = useState(c.phone ?? '');
  const doc = c.type === 'company' ? (reveal.data?.cnpj ? formatCNPJ(reveal.data.cnpj) : c.company?.cnpj_masked) : reveal.data?.cpf ? formatCPF(reveal.data.cpf) : c.cpf_masked;
  return (
    <Grid container spacing={4}>
      <Grid size={{ xs: 12, md: 6 }}>
        <KeyValue
          items={[
            { label: 'E-mail', value: `${c.email}${c.email_verified_at ? ' (verificado)' : ''}` },
            { label: 'Telefone', value: formatPhone(c.phone) },
            {
              label: c.type === 'company' ? 'CNPJ' : 'CPF',
              value: (
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                  <span className="num">{doc ?? '—'}</span>
                  {!reveal.data && (
                    <Can perm="customers.view_sensitive">
                      <Button size="small" onClick={() => reveal.mutate(undefined, { onError: (e) => notify.error(errorMessage(e)) })}>
                        Mostrar
                      </Button>
                    </Can>
                  )}
                </Stack>
              ),
            },
            ...(c.company ? [{ label: 'Empresa', value: <Link component={RouterLink} to={`/empresas/${c.company.id}`}>{c.company.legal_name}</Link> }] : []),
            { label: 'Tabela efetiva', value: c.effective_price_list?.name ?? 'Varejo' },
            { label: 'Marketing', value: c.marketing_opt_in ? 'Aceita comunicações' : 'Não aceita' },
            { label: 'Termos', value: `v${c.terms_version} em ${formatDate(c.terms_accepted_at)}` },
            { label: 'Último acesso', value: formatDateTime(c.last_login_at) },
            { label: 'Cadastro', value: formatDateTime(c.created_at) },
          ]}
        />
        <PriceListAssign currentId={c.price_list?.id ?? null} pending={patch.isPending} onSave={(id) => patch.mutateAsync({ price_list_id: id })} />
      </Grid>
      <Grid size={{ xs: 12, md: 6 }}>
        <Can perm="customers.update">
          <Typography variant="h4" component="h3" sx={{ mb: 2 }}>
            Editar dados
          </Typography>
          <Stack spacing={3}>
            <TextField label="Nome" value={name} onChange={(e) => setName(e.target.value)} />
            <TextField label="Telefone" value={phone} onChange={(e) => setPhone(e.target.value)} slotProps={{ htmlInput: { inputMode: 'tel' } }} />
            <Button
              variant="contained"
              sx={{ alignSelf: 'flex-start' }}
              disabled={patch.isPending || (name === c.name && phone === (c.phone ?? ''))}
              onClick={() =>
                patch.mutate(
                  { name, phone: phone || null },
                  { onSuccess: () => notify.success('Cliente salvo'), onError: (e) => notify.error(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e)) },
                )
              }
            >
              Salvar cliente
            </Button>
          </Stack>
        </Can>
      </Grid>
    </Grid>
  );
}

export default function CustomerDetailPage() {
  const id = Number(useParams().id);
  const q = useCustomer(id);
  const [tab, setTab] = useState(0);
  const [blockOpen, setBlockOpen] = useState(false);
  const [anonOpen, setAnonOpen] = useState(false);
  const [reason, setReason] = useState('');
  const block = useBlockCustomer(id);
  const unblock = useUnblockCustomer(id);
  const reset = useCustomerPasswordReset(id);
  const anonymize = useAnonymizeCustomer(id);
  useBreadcrumb(q.data?.name);
  if (q.isPending) return <LoadingBlock />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const c = q.data;
  const fail = (e: unknown) => notify.error(errorMessage(e));
  return (
    <>
      <PageHeader
        title={c.name}
        back={{ to: '/clientes', label: 'Clientes' }}
        chips={<ActiveChip active={c.is_active} on="Ativo" off="Bloqueado" />}
        subtitle={`${c.type === 'company' ? 'Pessoa jurídica' : 'Pessoa física'} · ${c.stats.orders_count} pedidos · ${formatBRL(c.stats.total_spent_cents)} comprados`}
        actions={
          <>
            <Can perm="customers.update">
              {c.is_active ? (
                <Button color="error" variant="outlined" onClick={() => setBlockOpen(true)}>Bloquear</Button>
              ) : (
                <Button variant="outlined" onClick={() => unblock.mutate(undefined, { onSuccess: () => notify.success('Cliente desbloqueado'), onError: fail })}>Desbloquear</Button>
              )}
              <Button onClick={() => reset.mutate(undefined, { onSuccess: () => notify.success('Link de redefinição enviado'), onError: fail })}>Enviar redefinição de senha</Button>
            </Can>
            <Can perm="customers.manage">
              <Button color="error" onClick={() => setAnonOpen(true)}>Anonimizar (LGPD)</Button>
            </Can>
          </>
        }
      />
      {c.anonymized_at && <Alert severity="info" sx={{ mb: 3 }}>Cliente anonimizado em {formatDateTime(c.anonymized_at)}.</Alert>}
      <Card>
        <Tabs value={tab} onChange={(_, v: number) => setTab(v)} sx={{ borderBottom: 1, borderColor: 'divider', px: 2 }} variant="scrollable">
          <Tab label="Dados" />
          <Tab label={`Endereços (${c.addresses?.length ?? 0})`} />
          <Tab label="Pedidos" />
          <Tab label="Preços específicos" />
          <Tab label="Auditoria" />
        </Tabs>
        <CardContent sx={{ p: 5 }}>
          {tab === 0 && <DataTab key={c.updated_at} c={c} />}
          {tab === 1 && (
            <List>
              {(c.addresses ?? []).map((a) => (
                <ListItem key={a.uuid} divider>
                  <ListItemText primary={`${a.label ?? 'Endereço'}${a.is_default ? ' (padrão)' : ''} — ${a.recipient_name}`} secondary={a.formatted} />
                </ListItem>
              ))}
              {!c.addresses?.length && <Typography variant="body2">Nenhum endereço.</Typography>}
            </List>
          )}
          {tab === 2 && <CustomerOrders customerId={c.id} />}
          {tab === 3 && <CustomerPricesTable filter={{ customer_id: c.id }} />}
          {tab === 4 && (
            <Box>
              <Can perm="audit_logs.view" fallback={<Typography variant="body2">Sem permissão para ver a auditoria.</Typography>}>
                <Button component={RouterLink} to={`/auditoria?auditable_type=customer&auditable_id=${c.id}`}>Ver auditoria deste cliente</Button>
              </Can>
            </Box>
          )}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={blockOpen}
        title={`Bloquear ${c.name}?`}
        description="O cliente não conseguirá entrar e as sessões ativas serão encerradas."
        confirmLabel="Bloquear cliente"
        destructive
        loading={block.isPending}
        onClose={() => setBlockOpen(false)}
        onConfirm={() => {
          if (reason.trim().length < 3) return notify.error('Informe o motivo (mín. 3 caracteres).');
          block.mutate(reason.trim(), { onSuccess: () => { notify.success('Cliente bloqueado'); setBlockOpen(false); }, onError: fail });
        }}
      >
        <TextField sx={{ mt: 2 }} label="Motivo" required value={reason} onChange={(e) => setReason(e.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} />
      </ConfirmDialog>
      <ConfirmDialog
        open={anonOpen}
        title={`Anonimizar ${c.name}?`}
        description="Os dados pessoais serão removidos de forma irreversível. Pedidos em andamento impedem a anonimização."
        confirmLabel="Anonimizar"
        destructive
        acknowledge="Entendo que esta ação é irreversível"
        loading={anonymize.isPending}
        onClose={() => setAnonOpen(false)}
        onConfirm={() => anonymize.mutate(undefined, { onSuccess: () => { notify.success('Cliente anonimizado'); setAnonOpen(false); }, onError: (e) => { setAnonOpen(false); fail(e); } })}
      />
    </>
  );
}
