import AddIcon from '@mui/icons-material/AddOutlined';
import { Alert, Button, FormControlLabel, MenuItem, Stack, Switch, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { Carrier } from '@/shared/api/types';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog } from '@/shared/ui/form';
import { deleteCarrier, testCarrier, useCarrierDrivers, useCarriers, useSaveCarrier } from '../api';

type SettingValue = string | number | boolean | null;

function CarrierDialog({ carrier, onClose }: { carrier: Carrier | null; onClose: () => void }) {
  const drivers = useCarrierDrivers().data ?? [];
  const save = useSaveCarrier(carrier?.id ?? null);
  const [name, setName] = useState(carrier?.name ?? '');
  const [code, setCode] = useState(carrier?.code ?? '');
  const [driver, setDriver] = useState(carrier?.driver ?? '');
  const [active, setActive] = useState(carrier?.is_active ?? true);
  const [settings, setSettings] = useState<Record<string, SettingValue>>(carrier?.settings ?? { timeout_ms: 5000 });
  const [changeCreds, setChangeCreds] = useState(!carrier);
  const [creds, setCreds] = useState('');
  const [error, setError] = useState<string | null>(null);
  const schema = drivers.find((d) => d.driver === driver)?.settings_schema ?? {};
  const fields: Record<string, 'string' | 'integer' | 'boolean'> = { timeout_ms: 'integer', cubic_divisor: 'integer', origin_postal_code: 'string', ...schema };

  const submit = async () => {
    setError(null);
    if (!/^[a-z0-9_]{1,40}$/.test(code)) return setError('Código: letras minúsculas, números e _ (até 40).');
    if (!name.trim() || name.length > 100) return setError('Informe o nome (até 100 caracteres).');
    if (!driver) return setError('Selecione a integração.');
    const t = Number(settings.timeout_ms ?? 5000);
    if (t < 500 || t > 15000) return setError('Timeout deve ficar entre 500 e 15000 ms.');
    let credentials: unknown;
    if (changeCreds) {
      if (creds.trim() === '') credentials = null;
      else {
        try {
          credentials = JSON.parse(creds);
        } catch {
          return setError('Credenciais: informe um JSON válido, ex.: {"token": "..."}');
        }
      }
    }
    const body: Record<string, unknown> = { name, driver, settings, is_active: active };
    if (!carrier) body.code = code;
    if (changeCreds) body.credentials = credentials;
    try {
      await save.mutateAsync(body);
      notify.success('Transportadora salva');
      onClose();
    } catch (e) {
      setError(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e));
    }
  };

  return (
    <FormDialog open title={carrier ? `Transportadora: ${carrier.name}` : 'Nova transportadora'} onClose={onClose} onSubmit={(e) => { e?.preventDefault(); void submit(); }} submitting={save.isPending} error={error}>
      <TextField label="Nome" required value={name} onChange={(e) => setName(e.target.value)} />
      <TextField label="Código" required value={code} disabled={!!carrier} onChange={(e) => setCode(e.target.value)} helperText="Imutável após a criação" />
      <TextField select label="Integração (driver)" required value={driver} onChange={(e) => setDriver(e.target.value)}>
        {drivers.map((d) => <MenuItem key={d.driver} value={d.driver}>{d.name}</MenuItem>)}
      </TextField>
      <Typography variant="overline">Configurações</Typography>
      {Object.entries(fields).map(([key, type]) =>
        type === 'boolean' ? (
          <FormControlLabel key={key} control={<Switch checked={!!settings[key]} onChange={(e) => setSettings({ ...settings, [key]: e.target.checked })} />} label={key} />
        ) : (
          <TextField key={key} label={key} value={settings[key] ?? ''} onChange={(e) => setSettings({ ...settings, [key]: type === 'integer' ? (e.target.value === '' ? null : Number(e.target.value.replace(/\D/g, ''))) : e.target.value })} />
        ),
      )}
      <Typography variant="overline">Credenciais (nunca exibidas)</Typography>
      {carrier && !changeCreds ? (
        <Stack direction="row" spacing={2} sx={{ alignItems: 'center' }}>
          <Typography variant="body2">{carrier.has_credentials ? '•••••••• (cadastradas)' : 'Não cadastradas'}</Typography>
          <Button size="small" onClick={() => setChangeCreds(true)}>Alterar</Button>
        </Stack>
      ) : (
        <TextField label="Credenciais (JSON)" multiline minRows={3} value={creds} onChange={(e) => setCreds(e.target.value)} helperText="Vazio = remover credenciais" slotProps={{ htmlInput: { autoComplete: 'off', spellCheck: false } }} />
      )}
      <FormControlLabel control={<Switch checked={active} onChange={(e) => setActive(e.target.checked)} />} label="Ativa" />
    </FormDialog>
  );
}

export default function CarriersPage() {
  const q = useCarriers();
  const [editing, setEditing] = useState<Carrier | 'new' | null>(null);
  const [testing, setTesting] = useState<number | null>(null);
  const remove = useRemoveWithConfirm<Carrier>({ remove: (c) => deleteCarrier(c.id), invalidate: ['admin', 'shipping'], successMessage: 'Transportadora excluída' });
  const runTest = async (c: Carrier) => {
    setTesting(c.id);
    try {
      const r = await testCarrier(c.id);
      if (r.ok) notify.success(`${c.name}: conexão OK (${r.duration_ms} ms)`);
      else notify.error(`${c.name}: ${r.message ?? 'falha na conexão'}`);
    } catch (e) {
      notify.error(errorMessage(e));
    } finally {
      setTesting(null);
    }
  };
  const columns: Column<Carrier>[] = [
    { key: 'name', header: 'Nome', render: (c) => c.name },
    { key: 'code', header: 'Código', render: (c) => c.code },
    { key: 'driver', header: 'Integração', render: (c) => c.driver },
    { key: 'creds', header: 'Credenciais', render: (c) => (c.has_credentials ? '••••••••' : '—') },
    { key: 'methods', header: 'Métodos', align: 'right', render: (c) => c.methods_count },
    { key: 'status', header: 'Status', render: (c) => <ActiveChip active={c.is_active} on="Ativa" off="Inativa" /> },
    {
      key: 'act',
      header: '',
      align: 'right',
      render: (c) => (
        <span onClick={(e) => e.stopPropagation()}>
          <Button size="small" disabled={testing === c.id} onClick={() => void runTest(c)}>Testar conexão</Button>
          <Button size="small" color="error" onClick={() => remove.ask(c)}>Excluir</Button>
        </span>
      ),
    },
  ];
  return (
    <>
      <PageHeader title="Transportadoras" actions={<Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Nova transportadora</Button>} />
      <Alert severity="info" sx={{ mb: 3 }}>Credenciais são somente de escrita. Para desativar uma transportadora usada por métodos, desmarque "Ativa".</Alert>
      <DataTable caption="Transportadoras" columns={columns} rows={q.data} rowKey={(c) => c.id} loading={q.isPending} error={q.error} onRetry={() => void q.refetch()} onRowClick={(c) => setEditing(c)} emptyTitle="Nenhuma transportadora" />
      {editing && <CarrierDialog carrier={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir ${remove.target?.name ?? ''}?`} confirmLabel="Excluir" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
