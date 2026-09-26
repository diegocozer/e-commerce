import CheckIcon from '@mui/icons-material/CheckCircleOutlined';
import CloseIcon from '@mui/icons-material/HighlightOffOutlined';
import RemoveIcon from '@mui/icons-material/RemoveCircleOutlined';
import { Alert, Box, Button, Card, CardContent, Checkbox, FormControlLabel, Grid, List, ListItem, ListItemIcon, ListItemText, MenuItem, Radio, RadioGroup, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { MethodTrace, SimulationRequest, SimulationResult } from '@/shared/api/extraTypes';
import type { AdminVariantPickerItem } from '@/shared/api/types';
import { formatCEP, maskCEP, onlyDigits } from '@/shared/formatters/document';
import { formatBRL } from '@/shared/formatters/money';
import { formatWeight } from '@/shared/formatters/quantity';
import { localInputToIso } from '@/shared/formatters/date';
import { MoneyField, PageHeader, QuantityField, VariantPicker } from '@/shared/ui';
import { useShippingMethods, useSimulate } from '../api';
import { kgToGrams, m3ToCm3 } from '../schemas/units';

const REASON: Record<string, string> = {
  weight_above_max: 'peso acima do máximo', weight_below_min: 'peso abaixo do mínimo', subtotal_below_min: 'subtotal abaixo do mínimo', subtotal_above_max: 'subtotal acima do máximo',
  volume_above_max: 'volume acima do máximo', volume_below_min: 'volume abaixo do mínimo', package_too_long: 'volume maior que o permitido', not_valid_yet: 'fora da vigência', expired: 'vigência encerrada',
  out_of_coverage: 'CEP fora da zona', no_rule_matched: 'nenhuma regra casou', destination_unresolved: 'destino não resolvido', carrier_timeout: 'timeout da transportadora', carrier_error: 'erro da transportadora',
  carrier_inactive: 'transportadora inativa', weight_above_limit: 'peso acima do limite', volume_above_limit: 'volume acima do limite', pickup_only_items: 'itens só para retirada', logistics_data_missing: 'dados logísticos faltando',
};
const tr = (code: string | undefined) => (code ? (REASON[code] ?? code) : '');

function RuleLine({ r }: { r: NonNullable<MethodTrace['rules']>[number] }) {
  const icon = r.result === 'matched' ? <CheckIcon color="success" /> : r.result === 'rejected' || r.result === 'zone_not_matched' ? <CloseIcon color="error" /> : <RemoveIcon color="disabled" />;
  const text =
    r.result === 'matched' ? 'casou' : r.result === 'rejected' ? `não casou: ${(r.reasons ?? []).map((x) => `${tr(x.code)}${x.detail ? ` (${x.detail})` : ''}`).join('; ')}` : r.result === 'zone_not_matched' ? 'zona não casou' : r.result === 'not_evaluated' ? 'não avaliada' : r.result;
  return (
    <ListItem dense sx={{ pl: 6 }}>
      <ListItemIcon sx={{ minWidth: 32 }}>{icon}</ListItemIcon>
      <ListItemText primary={<span style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13 }}>regra #{r.rule_id}{r.name ? ` ${r.name}` : ''}{r.priority !== undefined ? ` (prior. ${r.priority})` : ''} — {text}</span>} />
    </ListItem>
  );
}

function Trace({ result }: { result: SimulationResult }) {
  return (
    <List dense aria-label="Trace da avaliação">
      {result.methods.map((m) => (
        <Box key={m.method_id}>
          <ListItem>
            <ListItemIcon sx={{ minWidth: 32 }}>{m.status === 'option' ? <CheckIcon color="success" /> : m.status === 'unavailable' ? <CloseIcon color="error" /> : <RemoveIcon color="disabled" />}</ListItemIcon>
            <ListItemText
              primary={<strong>{m.name ?? m.code}</strong>}
              secondary={[
                m.status === 'option' ? 'gerou opção' : m.status === 'unavailable' ? `indisponível: ${tr(m.reason)}` : `ignorado${m.reason ? `: ${tr(m.reason)}` : ''}`,
                m.coverage ? (m.coverage.covered ? `zonas: ${m.coverage.via_zones.join(', ')}` : 'CEP fora das zonas') : null,
                m.effective_weight_grams !== undefined ? `peso efetivo ${formatWeight(m.effective_weight_grams)}` : null,
                m.duration_ms !== undefined ? `${(m.duration_ms / 1000).toLocaleString('pt-BR')} s` : null,
                m.detail ?? null,
                m.warnings?.length ? `avisos: ${m.warnings.join(', ')}` : null,
              ].filter(Boolean).join(' · ')}
            />
          </ListItem>
          {m.rules?.map((r) => <RuleLine key={r.rule_id} r={r} />)}
        </Box>
      ))}
    </List>
  );
}

type Mode = 'logistics' | 'items' | 'order';

export default function SimulatorPage() {
  const [sp] = useSearchParams();
  const methods = useShippingMethods().data ?? [];
  const sim = useSimulate();
  const [cep, setCep] = useState('');
  const [mode, setMode] = useState<Mode>('logistics');
  const [weight, setWeight] = useState('');
  const [volume, setVolume] = useState('');
  const [largest, setLargest] = useState('');
  const [items, setItems] = useState<{ variant: AdminVariantPickerItem | null; quantity: string }[]>([{ variant: null, quantity: '1' }]);
  const [orderId, setOrderId] = useState('');
  const [subtotal, setSubtotal] = useState<number | null>(null);
  const [coupon, setCoupon] = useState(false);
  const [at, setAt] = useState('');
  const [methodIds, setMethodIds] = useState<number[]>(sp.get('method_id') ? [Number(sp.get('method_id'))] : []);
  const [formError, setFormError] = useState<string | null>(null);

  const run = () => {
    setFormError(null);
    if (onlyDigits(cep).length !== 8) return setFormError('Informe um CEP válido.');
    const body: SimulationRequest = { postal_code: onlyDigits(cep) };
    if (mode === 'logistics') {
      const g = kgToGrams(weight);
      if (g === null || g <= 0) return setFormError('Informe o peso em kg.');
      body.logistics_override = { total_weight_grams: g, total_volume_cm3: volume ? (m3ToCm3(volume) ?? 0) : 0, largest_dimension_cm: largest ? Number(largest) : 0 };
    } else if (mode === 'items') {
      const valid = items.filter((i) => i.variant && Number(i.quantity) > 0);
      if (!valid.length) return setFormError('Adicione ao menos um item.');
      body.items = valid.map((i) => ({ variant_id: i.variant!.id, quantity: Number(i.quantity) }));
    } else {
      if (!Number(orderId)) return setFormError('Informe o id do pedido.');
      body.order_id = Number(orderId);
    }
    if (subtotal !== null) body.subtotal_cents = subtotal;
    if (coupon) body.coupon_free_shipping = true;
    const atIso = localInputToIso(at);
    if (atIso) body.at = atIso;
    if (methodIds.length) body.method_ids = methodIds;
    sim.mutate(body, { onError: (e) => setFormError(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e)) });
  };

  const r = sim.data;
  return (
    <>
      <PageHeader title="Simulador de frete" subtitle="Não grava cotações. Mostra as opções e como cada regra foi avaliada." />
      <Card sx={{ mb: 4 }}>
        <CardContent>
          <Box component="form" noValidate onSubmit={(e) => { e.preventDefault(); run(); }}>
            <Grid container spacing={3}>
              <Grid size={{ xs: 12, md: 3 }}>
                <TextField label="CEP de destino" required value={cep} onChange={(e) => setCep(maskCEP(e.target.value))} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
              </Grid>
              <Grid size={{ xs: 12, md: 3 }}>
                <MoneyField label="Subtotal (opcional)" value={subtotal} onChange={setSubtotal} helperText="Vazio = preço de visitante" />
              </Grid>
              <Grid size={{ xs: 12, md: 3 }}>
                <TextField type="datetime-local" label="Data de referência (vigência)" value={at} onChange={(e) => setAt(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
              </Grid>
              <Grid size={{ xs: 12, md: 3 }}>
                <TextField select label="Métodos" value={methodIds} onChange={(e) => setMethodIds((typeof e.target.value === 'string' ? [] : (e.target.value as unknown as number[])))} slotProps={{ select: { multiple: true, renderValue: (v) => ((v as number[]).length ? (v as number[]).map((id) => methods.find((m) => m.id === id)?.name).join(', ') : 'Todos') } }}>
                  {methods.map((m) => <MenuItem key={m.id} value={m.id}>{m.name}</MenuItem>)}
                </TextField>
              </Grid>
              <Grid size={12}>
                <RadioGroup row value={mode} onChange={(e) => setMode(e.target.value as Mode)} aria-label="Dados do carrinho">
                  <FormControlLabel value="logistics" control={<Radio />} label="Peso/volume" />
                  <FormControlLabel value="items" control={<Radio />} label="Itens" />
                  <FormControlLabel value="order" control={<Radio />} label="Itens de um pedido" />
                </RadioGroup>
              </Grid>
              {mode === 'logistics' && (
                <>
                  <Grid size={{ xs: 12, md: 3 }}><QuantityField label="Peso" unit="kg" value={weight} onChange={setWeight} required /></Grid>
                  <Grid size={{ xs: 12, md: 3 }}><QuantityField label="Volume" unit="m³" decimals={6} value={volume} onChange={setVolume} /></Grid>
                  <Grid size={{ xs: 12, md: 3 }}><QuantityField label="Maior dimensão" unit="cm" decimals={1} value={largest} onChange={setLargest} /></Grid>
                </>
              )}
              {mode === 'items' &&
                items.map((it, i) => (
                  <Grid key={i} size={12} container spacing={2}>
                    <Grid size={{ xs: 12, md: 8 }}><VariantPicker value={it.variant} onChange={(v) => setItems(items.map((x, j) => (j === i ? { ...x, variant: v } : x)))} /></Grid>
                    <Grid size={{ xs: 8, md: 3 }}><QuantityField label="Quantidade" value={it.quantity} onChange={(q) => setItems(items.map((x, j) => (j === i ? { ...x, quantity: q } : x)))} /></Grid>
                    <Grid size={{ xs: 4, md: 1 }}>{i === items.length - 1 && <Button onClick={() => setItems([...items, { variant: null, quantity: '1' }])}>+ Item</Button>}</Grid>
                  </Grid>
                ))}
              {mode === 'order' && <Grid size={{ xs: 12, md: 3 }}><TextField label="Id do pedido" value={orderId} onChange={(e) => setOrderId(e.target.value.replace(/\D/g, ''))} /></Grid>}
              <Grid size={12}>
                <FormControlLabel control={<Checkbox checked={coupon} onChange={(e) => setCoupon(e.target.checked)} />} label="Com cupom de frete grátis" />
              </Grid>
              <Grid size={12}>
                {formError && <Alert severity="error" sx={{ mb: 2 }}>{formError}</Alert>}
                <Button type="submit" variant="contained" disabled={sim.isPending}>Simular</Button>
              </Grid>
            </Grid>
          </Box>
        </CardContent>
      </Card>
      {r && (
        <Stack spacing={4} aria-live="polite">
          <Alert severity={r.destination.resolved ? 'info' : 'warning'}>
            Destino: {r.destination.city ? `${r.destination.city}/${r.destination.state} (IBGE ${r.destination.city_ibge_code})` : formatCEP(r.destination.postal_code)} · Zonas:{' '}
            {r.zones_matched.length ? r.zones_matched.map((z) => `${z.name} (${z.matched_by})`).join(', ') : 'nenhuma'} · Peso {formatWeight(r.logistics.total_weight_grams)}
          </Alert>
          <Card>
            <CardContent>
              <Typography variant="h3" component="h2" sx={{ mb: 2 }}>Opções retornadas</Typography>
              {r.options.length === 0 ? (
                <Typography variant="body2">Não há opções de entrega para este CEP.</Typography>
              ) : (
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Opção</TableCell>
                      <TableCell align="right">Preço</TableCell>
                      <TableCell>Prazo</TableCell>
                      <TableCell>Id</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {r.options.map((o) => (
                      <TableRow key={o.option_id}>
                        <TableCell>{o.name}</TableCell>
                        <TableCell align="right" className="num">{o.is_free ? `Grátis${o.original_price_cents ? ` (de ${formatBRL(o.original_price_cents)})` : ''}` : formatBRL(o.price_cents)}</TableCell>
                        <TableCell>{o.delivery_label}</TableCell>
                        <TableCell><code>{o.option_id}</code></TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardContent>
              <Typography variant="h3" component="h2">Trace (como chegamos aqui)</Typography>
              <Trace result={r} />
            </CardContent>
          </Card>
        </Stack>
      )}
    </>
  );
}
