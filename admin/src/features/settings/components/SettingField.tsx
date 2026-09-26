import { FormControlLabel, Grid, MenuItem, Stack, Switch, TextField, Typography } from '@mui/material';
import type { Setting } from '@/shared/api/types';
import { MoneyField, IntField, QuantityField } from '@/shared/ui';
import { SETTING_LABEL } from '../schemas';

type Obj = Record<string, unknown>;
const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

interface Props {
  setting: Setting;
  value: unknown;
  error: string | null;
  onChange: (v: unknown) => void;
}

/** Campo tipado por chave/tipo da whitelist. */
export function SettingField({ setting, value, error, onChange }: Props) {
  const label = SETTING_LABEL[setting.key] ?? setting.key;
  const help = error ?? setting.description ?? undefined;
  const k = setting.key;

  if (k === 'checkout.min_order_cents') return <MoneyField label={label} value={(value as number | null) ?? 0} onChange={(c) => onChange(c ?? 0)} error={!!error} helperText={help} />;
  if (k === 'store.address') {
    const a = (value ?? {}) as Obj;
    const set = (f: string, v: string) => onChange({ ...a, [f]: v || (f === 'complement' ? null : '') });
    return (
      <Stack spacing={1}>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>{label}</Typography>
        <Grid container spacing={2}>
          <Grid size={{ xs: 12, md: 3 }}><TextField label="CEP" value={(a.postal_code as string) ?? ''} onChange={(e) => set('postal_code', e.target.value.replace(/\D/g, '').slice(0, 8))} /></Grid>
          <Grid size={{ xs: 12, md: 6 }}><TextField label="Rua" value={(a.street as string) ?? ''} onChange={(e) => set('street', e.target.value)} /></Grid>
          <Grid size={{ xs: 12, md: 3 }}><TextField label="Número" value={(a.number as string) ?? ''} onChange={(e) => set('number', e.target.value)} /></Grid>
          <Grid size={{ xs: 12, md: 4 }}><TextField label="Complemento" value={(a.complement as string) ?? ''} onChange={(e) => set('complement', e.target.value)} /></Grid>
          <Grid size={{ xs: 12, md: 3 }}><TextField label="Bairro" value={(a.district as string) ?? ''} onChange={(e) => set('district', e.target.value)} /></Grid>
          <Grid size={{ xs: 8, md: 3 }}><TextField label="Cidade" value={(a.city as string) ?? ''} onChange={(e) => set('city', e.target.value)} /></Grid>
          <Grid size={{ xs: 4, md: 2 }}>
            <TextField select label="UF" value={(a.state as string) ?? ''} onChange={(e) => set('state', e.target.value)}>
              {UFS.map((u) => <MenuItem key={u} value={u}>{u}</MenuItem>)}
            </TextField>
          </Grid>
          <Grid size={{ xs: 12, md: 3 }}><TextField label="Código IBGE" value={(a.city_ibge_code as string) ?? ''} onChange={(e) => set('city_ibge_code', e.target.value.replace(/\D/g, '').slice(0, 7))} /></Grid>
        </Grid>
        {error && <Typography variant="caption" color="error">{error}</Typography>}
      </Stack>
    );
  }
  if (k === 'store.social_links') {
    const o = (value ?? {}) as Record<string, string | null>;
    return (
      <Stack spacing={2}>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>{label}</Typography>
        {(['instagram', 'facebook', 'youtube'] as const).map((n) => (
          <TextField key={n} label={n[0].toUpperCase() + n.slice(1)} value={o[n] ?? ''} onChange={(e) => onChange({ ...o, [n]: e.target.value || null })} placeholder="https://" />
        ))}
        {error && <Typography variant="caption" color="error">{error}</Typography>}
      </Stack>
    );
  }
  if (k === 'storefront.free_shipping_banner') {
    const b = (value ?? { enabled: false, threshold_cents: 0, text: '' }) as { enabled: boolean; threshold_cents: number; text: string };
    return (
      <Stack spacing={2}>
        <FormControlLabel control={<Switch checked={b.enabled} onChange={(e) => onChange({ ...b, enabled: e.target.checked })} />} label={label} />
        <MoneyField label="A partir de" value={b.threshold_cents} onChange={(c) => onChange({ ...b, threshold_cents: c ?? 0 })} />
        <TextField label="Texto do banner" value={b.text} onChange={(e) => onChange({ ...b, text: e.target.value })} error={!!error} helperText={error ?? `${b.text.length}/160 — o banner é só comunicação; a regra real fica em Frete › Regras.`} />
      </Stack>
    );
  }
  switch (setting.type) {
    case 'boolean':
      return <FormControlLabel control={<Switch checked={!!value} onChange={(e) => onChange(e.target.checked)} />} label={label} />;
    case 'integer':
      return <IntField label={label} value={(value as number | null) ?? null} onChange={onChange} error={!!error} helperText={help} />;
    case 'decimal':
      return <QuantityField label={label} value={value === null || value === undefined ? '' : String(value)} onChange={(v) => onChange(v === '' ? null : Number(v))} error={!!error} helperText={help} />;
    case 'string_list':
      return (
        <TextField
          label={label}
          multiline
          minRows={2}
          value={Array.isArray(value) ? (value as string[]).join('\n') : ''}
          onChange={(e) => onChange(e.target.value.split(/[\n,;]/).map((x) => x.trim()).filter(Boolean))}
          error={!!error}
          helperText={help ?? 'Um por linha'}
        />
      );
    case 'text':
      return <TextField label={label} multiline minRows={6} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} error={!!error} helperText={help ?? 'Texto puro (sem HTML).'} />;
    case 'object':
      return <TextField label={`${label} (JSON)`} multiline minRows={3} value={JSON.stringify(value, null, 2)} onChange={(e) => { try { onChange(JSON.parse(e.target.value)); } catch { /* aguarda JSON válido */ } }} error={!!error} helperText={help} />;
    default:
      return <TextField label={label} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} error={!!error} helperText={help} />;
  }
}
