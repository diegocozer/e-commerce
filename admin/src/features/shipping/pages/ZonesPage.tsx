import AddIcon from '@mui/icons-material/AddOutlined';
import { Button } from '@mui/material';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import type { ShippingZone } from '@/shared/api/types';
import { formatCEP } from '@/shared/formatters/document';
import { useRemoveWithConfirm } from '@/shared/hooks/useRemoveWithConfirm';
import { ActiveChip, ConfirmDialog, DataTable, PageHeader, type Column } from '@/shared/ui';
import { deleteZone, useShippingZones } from '../api';

function coverage(z: ShippingZone): string {
  const parts = [
    ...z.postal_ranges.map((r) => `${formatCEP(r.start_postal_code)}–${formatCEP(r.end_postal_code)}`),
    ...z.cities.map((c) => `${c.city_name}/${c.state}`),
    ...z.states,
  ];
  return parts.length > 4 ? `${parts.slice(0, 4).join(', ')} +${parts.length - 4}` : parts.join(', ') || '—';
}

export default function ZonesPage() {
  const q = useShippingZones();
  const navigate = useNavigate();
  const remove = useRemoveWithConfirm<ShippingZone>({ remove: (z) => deleteZone(z.id), invalidate: ['admin', 'shipping'], successMessage: 'Zona excluída' });
  const columns: Column<ShippingZone>[] = [
    { key: 'name', header: 'Nome', render: (z) => z.name },
    { key: 'cov', header: 'Cobertura (CEP, cidades, UFs)', render: coverage },
    { key: 'rules', header: 'Regras', align: 'right', render: (z) => z.rules_count },
    { key: 'status', header: 'Status', render: (z) => <ActiveChip active={z.is_active} on="Ativa" off="Inativa" /> },
    { key: 'act', header: '', align: 'right', render: (z) => <Button size="small" color="error" onClick={(e) => { e.stopPropagation(); remove.ask(z); }}>Excluir</Button> },
  ];
  return (
    <>
      <PageHeader title="Zonas / regiões" actions={<Button variant="contained" startIcon={<AddIcon />} component={RouterLink} to="/frete/zonas/nova">Nova zona</Button>} />
      <DataTable caption="Zonas de frete" columns={columns} rows={q.data} rowKey={(z) => z.id} loading={q.isPending} error={q.error} onRetry={() => void q.refetch()} onRowClick={(z) => navigate(`/frete/zonas/${z.id}`)} emptyTitle="Nenhuma zona cadastrada" />
      <ConfirmDialog open={!!remove.target} title={`Excluir a zona "${remove.target?.name ?? ''}"?`} description="Zonas usadas por regras não podem ser excluídas." confirmLabel="Excluir zona" destructive loading={remove.loading} onConfirm={remove.confirm} onClose={remove.cancel} />
    </>
  );
}
