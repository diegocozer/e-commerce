import { Alert, Box, Button, Card, Checkbox, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { useEffect, useMemo, useState } from 'react';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { Permission, PermissionName, Role } from '@/shared/api/types';
import { PERMISSION_CATALOG, PERMISSION_GROUP_LABEL } from '@/shared/auth';
import { useUnsavedChangesGuard } from '@/shared/hooks/useUnsavedChangesGuard';
import { ConfirmDialog, ErrorState, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { UnsavedChangesDialog } from '@/shared/ui/form';
import { deleteRole, usePermissions, useRoles, useSaveRole } from '../api';

/** Permissão "ver" implícita quando outra do mesmo grupo é marcada (UX §5.12: "Editar implica Ver"). */
const VIEW_OF: Partial<Record<Permission['group'], PermissionName>> = {
  products: 'products.view', inventory: 'inventory.view', orders: 'orders.view', customers: 'customers.view', payments: 'payments.view',
};

export function togglePermission(current: PermissionName[], perm: Permission, checked: boolean): PermissionName[] {
  const set = new Set(current);
  if (checked) {
    set.add(perm.name);
    const view = VIEW_OF[perm.group];
    if (view) set.add(view);
  } else {
    set.delete(perm.name);
    if (VIEW_OF[perm.group] === perm.name) {
      // Remover "ver" remove as demais do grupo.
      PERMISSION_CATALOG.filter((p) => p.group === perm.group).forEach((p) => set.delete(p.name));
    }
  }
  return [...set];
}

export default function RolesPage() {
  const roles = useRoles();
  const perms = usePermissions();
  const [selectedId, setSelectedId] = useState<number | 'new' | null>(null);
  const [newName, setNewName] = useState('');
  const [draft, setDraft] = useState<PermissionName[]>([]);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const role: Role | undefined = roles.data?.find((r) => r.id === selectedId);
  const save = useSaveRole(selectedId === 'new' || selectedId === null ? null : selectedId);
  const catalog = perms.data ?? PERMISSION_CATALOG;
  const groups = useMemo(() => Object.entries(PERMISSION_GROUP_LABEL).map(([g, label]) => ({ g, label, perms: catalog.filter((p) => p.group === g) })).filter((x) => x.perms.length), [catalog]);
  const locked = role?.name === 'super-admin';
  const dirty = selectedId === 'new' || (!!role && [...draft].sort().join() !== [...role.permissions].sort().join());
  const blocker = useUnsavedChangesGuard(dirty && !save.isPending);

  useEffect(() => {
    if (selectedId === null && roles.data?.length) setSelectedId(roles.data[0].id);
  }, [roles.data, selectedId]);
  useEffect(() => {
    if (role) setDraft(role.permissions);
    if (selectedId === 'new') setDraft([]);
  }, [role, selectedId]);

  const onSave = () => {
    const body = selectedId === 'new' ? { name: newName, permissions: draft } : { permissions: draft };
    save.mutate(body, {
      onSuccess: (res) => {
        notify.success('Papel salvo');
        setSelectedId(res.data.id);
      },
      onError: (e) => notify.error(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e)),
    });
  };

  if (roles.isPending) return <LoadingBlock />;
  if (roles.error) return <ErrorState error={roles.error} onRetry={() => void roles.refetch()} />;

  return (
    <>
      <PageHeader
        title="Papéis & permissões"
        actions={
          <>
            <Button onClick={() => { setSelectedId('new'); setNewName(role ? `${role.name}-copia` : ''); setDraft(role?.permissions ?? []); }}>
              {role ? 'Duplicar papel' : 'Novo papel'}
            </Button>
            {role && !role.is_system && <Button color="error" onClick={() => setConfirmDelete(true)}>Excluir papel</Button>}
            <Button variant="contained" onClick={onSave} disabled={!dirty || locked || save.isPending}>Salvar</Button>
          </>
        }
      />
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={3} sx={{ mb: 3 }}>
        <TextField select label="Papel" value={selectedId ?? ''} onChange={(e) => setSelectedId(e.target.value === 'new' ? 'new' : Number(e.target.value))} sx={{ maxWidth: 320 }}>
          {roles.data?.map((r) => <MenuItem key={r.id} value={r.id}>{r.label} ({r.users_count} usuários)</MenuItem>)}
          {selectedId === 'new' && <MenuItem value="new">Novo papel</MenuItem>}
        </TextField>
        {selectedId === 'new' && <TextField label="Identificador do novo papel" value={newName} onChange={(e) => setNewName(e.target.value.toLowerCase())} helperText="3 a 50 caracteres: a–z, 0–9 e hífen" sx={{ maxWidth: 320 }} />}
      </Stack>
      {locked && <Alert severity="info" sx={{ mb: 3 }}>Super Admin tem todas as permissões e não pode ser editado.</Alert>}
      <Card>
        <TableContainer>
          <Table size="small" aria-label="Matriz de permissões">
            <TableHead>
              <TableRow>
                <TableCell>Recurso</TableCell>
                <TableCell>Permissões</TableCell>
                <TableCell align="right">Todas</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {groups.map(({ g, label, perms: ps }) => {
                const all = ps.every((p) => draft.includes(p.name));
                return (
                  <TableRow key={g}>
                    <TableCell component="th" scope="row" sx={{ fontWeight: 600, width: 180 }}>{label}</TableCell>
                    <TableCell>
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
                        {ps.map((p) => (
                          <Box key={p.name} component="label" sx={{ display: 'inline-flex', alignItems: 'center', mr: 2 }}>
                            <Checkbox size="small" disabled={locked} checked={locked || draft.includes(p.name)} onChange={(e) => setDraft(togglePermission(draft, p, e.target.checked))} />
                            <Typography variant="body2">{p.label}</Typography>
                          </Box>
                        ))}
                      </Box>
                    </TableCell>
                    <TableCell align="right">
                      <Checkbox
                        disabled={locked}
                        checked={locked || all}
                        indeterminate={!all && ps.some((p) => draft.includes(p.name))}
                        onChange={(e) => setDraft(e.target.checked ? Array.from(new Set([...draft, ...ps.map((p) => p.name)])) : draft.filter((d) => !ps.some((p) => p.name === d)))}
                        slotProps={{ input: { 'aria-label': `Marcar todas de ${label}` } }}
                      />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      </Card>
      <ConfirmDialog
        open={confirmDelete}
        title={`Excluir o papel "${role?.label ?? ''}"?`}
        description="Papéis com usuários não podem ser excluídos."
        confirmLabel="Excluir papel"
        destructive
        acknowledge="Entendo que esta ação não pode ser desfeita"
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => {
          setConfirmDelete(false);
          if (!role) return;
          deleteRole(role.id)
            .then(() => {
              notify.success('Papel excluído');
              setSelectedId(null);
              void roles.refetch();
            })
            .catch((e: unknown) => notify.error(errorMessage(e)));
        }}
      />
      <UnsavedChangesDialog blocker={blocker} />
    </>
  );
}
