import AddIcon from '@mui/icons-material/AddOutlined';
import { zodResolver } from '@hookform/resolvers/zod';
import { Autocomplete, Button, Chip, TextField } from '@mui/material';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { errorMessage } from '@/shared/api/errors';
import type { AdminUser } from '@/shared/api/types';
import { useMe } from '@/shared/auth';
import { formatDateTime, formatRelative } from '@/shared/formatters/date';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, FilterSelect, ListToolbar, notify, PageHeader, type Column } from '@/shared/ui';
import { FormDialog, RHFTextField } from '@/shared/ui/form';
import { deleteUser, useAdminUsers, useRoles, useSaveUser, useUserAction } from '../api';

const userSchema = z.object({
  name: z.string().trim().min(3, 'Nome: 3 a 150 caracteres.').max(150, 'Nome: 3 a 150 caracteres.'),
  email: z.string().trim().pipe(z.email('Informe um e-mail válido.')),
  roles: z.array(z.string()).min(1, 'Selecione ao menos um papel.'),
});
type UserForm = z.infer<typeof userSchema>;

function UserDialog({ user, isSelf, onClose }: { user: AdminUser | null; isSelf: boolean; onClose: () => void }) {
  const roles = useRoles().data ?? [];
  const save = useSaveUser(user?.id ?? null);
  const { control, handleSubmit, setError } = useForm<UserForm>({ resolver: zodResolver(userSchema), defaultValues: { name: user?.name ?? '', email: user?.email ?? '', roles: user?.roles ?? [] } });
  const { error, run } = useFormSubmit(setError);
  const onSubmit = handleSubmit(async (v) => {
    const body = isSelf ? { name: v.name, email: v.email.toLowerCase(), roles: user!.roles } : { ...v, email: v.email.toLowerCase() };
    if (await run(() => save.mutateAsync(body))) {
      notify.success(user ? 'Usuário salvo' : 'Convite enviado');
      onClose();
    }
  });
  const label = (name: string) => roles.find((r) => r.name === name)?.label ?? name;
  return (
    <FormDialog open title={user ? `Usuário: ${user.name}` : 'Convidar usuário'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} submitLabel={user ? 'Salvar' : 'Enviar convite'}>
      <RHFTextField control={control} name="name" label="Nome" required />
      <RHFTextField control={control} name="email" label="E-mail" type="email" required />
      <Controller
        control={control}
        name="roles"
        render={({ field, fieldState }) => (
          <Autocomplete
            multiple
            disabled={isSelf}
            options={roles.map((r) => r.name)}
            value={field.value}
            onChange={(_, v) => field.onChange(v)}
            getOptionLabel={label}
            renderValue={(vals, getItemProps) => vals.map((v, index) => <Chip {...getItemProps({ index })} key={v} label={label(v)} size="small" />)}
            renderInput={(p) => <TextField {...p} label="Papéis" required error={!!fieldState.error} helperText={fieldState.error?.message ?? (isSelf ? 'Você não pode alterar os próprios papéis.' : undefined)} />}
          />
        )}
      />
      {!user && <span>O usuário receberá um link para definir a senha (válido por 72 h).</span>}
    </FormDialog>
  );
}

export default function UsersPage() {
  const me = useMe().data;
  const { params, apiParams, update, clear, activeFilterCount } = useListParams();
  const q = useAdminUsers(apiParams);
  const roles = useRoles().data ?? [];
  const action = useUserAction();
  const [editing, setEditing] = useState<AdminUser | 'new' | null>(null);
  const remove = useRemoveWithConfirm<AdminUser>({ remove: (u) => deleteUser(u.id), invalidate: ['admin', 'users'], successMessage: 'Usuário excluído' });
  const act = (id: number, a: 'activate' | 'deactivate' | 'password-reset', msg: string) => action.mutate({ id, action: a }, { onSuccess: () => notify.success(msg), onError: (e) => notify.error(errorMessage(e)) });
  const columns: Column<AdminUser>[] = [
    { key: 'name', header: 'Nome', render: (u) => `${u.name}${u.id === me?.user.id ? ' (você)' : ''}` },
    { key: 'email', header: 'E-mail', render: (u) => u.email },
    { key: 'roles', header: 'Papéis', render: (u) => u.roles.map((r) => <Chip key={r} size="small" label={roles.find((x) => x.name === r)?.label ?? r} sx={{ mr: 1 }} />) },
    { key: 'last', header: 'Último acesso', hideBelow: 'md', render: (u) => <span title={formatDateTime(u.last_login_at)}>{u.last_login_at ? formatRelative(u.last_login_at) : 'Nunca'}</span> },
    { key: 'status', header: 'Status', render: (u) => <ActiveChip active={u.is_active} /> },
    {
      key: 'act',
      header: '',
      align: 'right',
      render: (u) =>
        u.id !== me?.user.id && (
          <span onClick={(e) => e.stopPropagation()}>
            {u.is_active ? <Button size="small" onClick={() => act(u.id, 'deactivate', 'Usuário desativado')}>Desativar</Button> : <Button size="small" onClick={() => act(u.id, 'activate', 'Usuário ativado')}>Ativar</Button>}
            <Button size="small" onClick={() => act(u.id, 'password-reset', 'Link de redefinição enviado')}>Redefinir senha</Button>
            <Button size="small" color="error" onClick={() => remove.ask(u)}>Excluir</Button>
          </span>
        ),
    },
  ];
  return (
    <>
      <PageHeader title="Usuários do painel" count={q.data?.meta.total} actions={<Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>Convidar usuário</Button>} />
      <ListToolbar q={params.q} onSearch={(v) => update({ q: v })} placeholder="Nome ou e-mail…" onClear={clear} activeCount={activeFilterCount}>
        <FilterSelect label="Papel" value={params.filters.role ?? ''} onChange={(v) => update({ role: v })} options={roles.map((r) => ({ value: r.name, label: r.label }))} />
        <FilterSelect label="Status" value={params.filters.is_active ?? ''} onChange={(v) => update({ is_active: v })} options={[{ value: '1', label: 'Ativos' }, { value: '0', label: 'Inativos' }]} width={140} />
      </ListToolbar>
      <DataTable caption="Usuários" columns={columns} rows={q.data?.data} rowKey={(u) => u.id} meta={q.data?.meta} loading={q.isPending} fetching={q.isFetching} error={q.error} onRetry={() => void q.refetch()} onPageChange={(page) => update({ page }, false)} onPerPageChange={(per_page) => update({ per_page })} onRowClick={(u) => setEditing(u)} emptyTitle="Nenhum usuário" filtered={activeFilterCount > 0} onClearFilters={clear} />
      {editing && <UserDialog user={editing === 'new' ? null : editing} isSelf={editing !== 'new' && editing.id === me?.user.id} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir ${remove.target?.name ?? ''}?`} description="Prefira desativar: o histórico de auditoria é mantido de qualquer forma." confirmLabel="Excluir usuário" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
