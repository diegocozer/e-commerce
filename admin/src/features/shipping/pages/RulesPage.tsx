import AddIcon from '@mui/icons-material/AddOutlined';
import ArrowDownIcon from '@mui/icons-material/ArrowDownwardOutlined';
import ArrowUpIcon from '@mui/icons-material/ArrowUpwardOutlined';
import ContentCopyIcon from '@mui/icons-material/ContentCopyOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/EditOutlined';
import { Alert, Box, Button, Card, CardContent, Chip, IconButton, Stack, Switch, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import type { ShippingRule } from '@/shared/api/types';
import { useListParams } from '@/shared/hooks/useListParams';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ConfirmDialog, EmptyState, ErrorState, FilterSelect, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { deleteRule, useDuplicateRule, usePatchRule, useReorderRules, useRules, useShippingMethods, useShippingZones } from '../api';
import { RuleDialog } from '../components/RuleDialog';
import { daysLabel, ruleConditionChips, rulePriceLabel } from '../schemas/rule';

interface Group {
  key: string;
  method_id: number;
  zone_id: number | null;
  rules: ShippingRule[];
}

export default function RulesPage() {
  const { params, apiParams, update } = useListParams();
  const rules = useRules({ method_id: apiParams.method_id, zone_id: apiParams.zone_id });
  const methods = useShippingMethods().data ?? [];
  const zones = useShippingZones().data ?? [];
  const [dialog, setDialog] = useState<{ rule: ShippingRule | null; method_id: number | null; zone_id: number | null } | null>(null);
  const reorder = useReorderRules();
  const duplicate = useDuplicateRule();
  const patch = usePatchRule();
  const remove = useRemoveWithConfirm<ShippingRule>({ remove: (r) => deleteRule(r.id), invalidate: ['admin', 'shipping'], successMessage: 'Regra excluída' });
  const fail = (e: unknown) => notify.error(errorMessage(e));

  const groups: Group[] = [];
  for (const r of rules.data ?? []) {
    const key = `${r.method_id}:${r.zone_id ?? 'null'}`;
    let g = groups.find((x) => x.key === key);
    if (!g) groups.push((g = { key, method_id: r.method_id, zone_id: r.zone_id, rules: [] }));
    g.rules.push(r);
  }
  const byMethod = methods.map((m) => ({ method: m, groups: groups.filter((g) => g.method_id === m.id) })).filter((x) => x.groups.length > 0);
  const zoneName = (id: number | null) => (id === null ? 'Qualquer destino (global)' : (zones.find((z) => z.id === id)?.name ?? `Zona #${id}`));

  const move = (g: Group, index: number, dir: -1 | 1) => {
    const ids = g.rules.map((r) => r.id);
    [ids[index], ids[index + dir]] = [ids[index + dir], ids[index]];
    reorder.mutate({ method_id: g.method_id, zone_id: g.zone_id, ids }, { onSuccess: () => notify.success('Prioridades atualizadas'), onError: fail });
  };

  return (
    <>
      <PageHeader
        title="Regras de frete"
        actions={
          <>
            <Button component={RouterLink} to={`/frete/simulador${params.filters.method_id ? `?method_id=${params.filters.method_id}` : ''}`}>Testar no simulador</Button>
            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setDialog({ rule: null, method_id: params.filters.method_id ? Number(params.filters.method_id) : null, zone_id: params.filters.zone_id && params.filters.zone_id !== 'null' ? Number(params.filters.zone_id) : null })}>
              Nova regra
            </Button>
          </>
        }
      />
      <Alert severity="info" sx={{ mb: 3 }}>
        Para cada método e zona, vale a primeira regra (menor prioridade) cujas condições casarem.
      </Alert>
      <Stack direction="row" spacing={2} sx={{ mb: 3 }}>
        <FilterSelect label="Método" value={params.filters.method_id ?? ''} onChange={(v) => update({ method_id: v })} options={methods.filter((m) => m.type === 'own_delivery' || m.type === 'table_rate').map((m) => ({ value: String(m.id), label: m.name }))} width={220} />
        <FilterSelect label="Zona" value={params.filters.zone_id ?? ''} onChange={(v) => update({ zone_id: v })} options={[{ value: 'null', label: 'Global (sem zona)' }, ...zones.map((z) => ({ value: String(z.id), label: z.name }))]} width={220} />
      </Stack>
      {rules.isPending && <LoadingBlock />}
      {rules.error && <ErrorState error={rules.error} onRetry={() => void rules.refetch()} />}
      {rules.data && byMethod.length === 0 && <Card><EmptyState title="Nenhuma regra cadastrada" /></Card>}
      <Stack spacing={4}>
        {byMethod.map(({ method, groups: gs }) => (
          <Card key={method.id}>
            <CardContent>
              <Typography variant="h2" component="h2">{method.name}</Typography>
              {gs.map((g) => (
                <Box key={g.key} sx={{ mt: 3 }}>
                  <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center' }}>
                    <Typography variant="h3" component="h3">{zoneName(g.zone_id)}</Typography>
                    <Button size="small" startIcon={<AddIcon />} onClick={() => setDialog({ rule: null, method_id: g.method_id, zone_id: g.zone_id })}>Regra neste grupo</Button>
                  </Stack>
                  <Table size="small" aria-label={`Regras de ${method.name} em ${zoneName(g.zone_id)}`}>
                    <TableHead>
                      <TableRow>
                        <TableCell>Prior.</TableCell>
                        <TableCell>Condições</TableCell>
                        <TableCell align="right">Preço</TableCell>
                        <TableCell>Prazo</TableCell>
                        <TableCell>Ativa</TableCell>
                        <TableCell align="right">Ações</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {g.rules.map((r, i) => {
                        const chips = ruleConditionChips(r);
                        return (
                          <TableRow key={r.id} hover>
                            <TableCell className="num">
                              <Stack direction="row" sx={{ alignItems: 'center' }}>
                                {r.priority}
                                <IconButton aria-label={`Subir prioridade de ${r.name}`} disabled={i === 0 || reorder.isPending} onClick={() => move(g, i, -1)}><ArrowUpIcon fontSize="small" /></IconButton>
                                <IconButton aria-label={`Descer prioridade de ${r.name}`} disabled={i === g.rules.length - 1 || reorder.isPending} onClick={() => move(g, i, 1)}><ArrowDownIcon fontSize="small" /></IconButton>
                              </Stack>
                            </TableCell>
                            <TableCell>
                              <Typography variant="body2" sx={{ fontWeight: 600 }}>{r.name}</Typography>
                              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1, mt: 0.5 }}>
                                {chips.length ? chips.map((c) => <Chip key={c} size="small" variant="outlined" label={c} />) : <Chip size="small" label="Sem condições" />}
                              </Stack>
                              <Typography variant="caption" color="text.secondary">{r.summary}</Typography>
                            </TableCell>
                            <TableCell align="right" className="num">{rulePriceLabel(r)}</TableCell>
                            <TableCell>{daysLabel(r.delivery_days_min ?? method.delivery_days_min, r.delivery_days_max ?? method.delivery_days_max)}</TableCell>
                            <TableCell>
                              <Switch checked={r.is_active} slotProps={{ input: { 'aria-label': `Regra ${r.name} ativa` } }} onChange={(e) => patch.mutate({ id: r.id, body: { is_active: e.target.checked } }, { onError: fail })} />
                            </TableCell>
                            <TableCell align="right">
                              <IconButton aria-label={`Editar ${r.name}`} onClick={() => setDialog({ rule: r, method_id: r.method_id, zone_id: r.zone_id })}><EditIcon fontSize="small" /></IconButton>
                              <IconButton aria-label={`Duplicar ${r.name}`} onClick={() => duplicate.mutate(r.id, { onSuccess: () => notify.success('Regra duplicada (inativa)'), onError: fail })}><ContentCopyIcon fontSize="small" /></IconButton>
                              <IconButton aria-label={`Excluir ${r.name}`} onClick={() => remove.ask(r)}><DeleteIcon fontSize="small" /></IconButton>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </Box>
              ))}
            </CardContent>
          </Card>
        ))}
      </Stack>
      {dialog && <RuleDialog rule={dialog.rule} defaults={{ method_id: dialog.method_id, zone_id: dialog.zone_id }} methods={methods} zones={zones} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={!!remove.target} title={`Excluir a regra "${remove.target?.name ?? ''}"?`} confirmLabel="Excluir regra" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
