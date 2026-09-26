import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Divider, Grid, Typography } from '@mui/material';
import { useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import type { RuleWarning } from '@/shared/api/extraTypes';
import type { ShippingMethod, ShippingRule, ShippingZone } from '@/shared/api/types';
import { SHIPPING_PRICE_TYPE } from '@/shared/formatters/labels';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { notify } from '@/shared/ui';
import { FormDialog, RHFDateTimeField, RHFIntField, RHFMoneyField, RHFPercentField, RHFQuantityField, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { useSaveRule } from '../api';
import { daysLabel, emptyRule, ruleConditionChips, ruleFormToPayload, rulePriceLabel, ruleSchema, ruleToForm, type RuleForm } from '../schemas/rule';

const WARNING_TEXT: Record<RuleWarning, string> = {
  tie_broken_by_id: 'Existe outra regra com a mesma prioridade neste grupo; o desempate será pelo id.',
  free_rule_without_coverage_limit: 'Regra grátis sem zona em método sem regras zonadas: vale para o Brasil todo.',
  rule_never_reachable: 'Esta regra nunca será usada: outra regra de prioridade menor cobre todos os casos.',
};

/** Frase de prévia (UX §5.10.3): "Para Entrega própria em Blumenau, pedidos com até 10 kg pagam R$ 20,00 e chegam em 1 dia útil." */
export function RulePreview({ v, methods, zones }: { v: RuleForm; methods: ShippingMethod[]; zones: ShippingZone[] }) {
  const payload = ruleFormToPayload(v) as unknown as ShippingRule;
  const method = methods.find((m) => m.id === v.method_id);
  const zone = zones.find((z) => z.id === v.zone_id);
  const chips = ruleConditionChips(payload);
  return (
    <Alert severity="info" icon={false} data-testid="rule-preview">
      Para <strong>{method?.name ?? '…'}</strong> em <strong>{zone?.name ?? 'qualquer destino'}</strong>, pedidos{' '}
      {chips.length ? <>com <strong>{chips.join(', ')}</strong></> : 'sem condições'} pagam <strong>{rulePriceLabel(payload)}</strong> e chegam em{' '}
      <strong>{daysLabel(v.delivery_days_min ?? method?.delivery_days_min ?? null, v.delivery_days_max ?? method?.delivery_days_max ?? null)}</strong>.
    </Alert>
  );
}

interface Props {
  rule: ShippingRule | null;
  defaults?: { method_id: number | null; zone_id: number | null };
  methods: ShippingMethod[];
  zones: ShippingZone[];
  onClose: () => void;
}

export function RuleDialog({ rule, defaults, methods, zones, onClose }: Props) {
  const ruleMethods = methods.filter((m) => m.type === 'own_delivery' || m.type === 'table_rate');
  const schema = useMemo(() => ruleSchema(methods), [methods]);
  const save = useSaveRule(rule?.id ?? null);
  const [warnings, setWarnings] = useState<RuleWarning[]>([]);
  const { control, handleSubmit, setError } = useForm<RuleForm>({ resolver: zodResolver(schema), defaultValues: rule ? ruleToForm(rule) : emptyRule(defaults?.method_id ?? ruleMethods[0]?.id ?? null, defaults?.zone_id ?? null) });
  const values = useWatch({ control }) as RuleForm;
  const type = values.price_type;
  const { error, run } = useFormSubmit(setError, {
    min_weight_grams: 'min_weight_kg', max_weight_grams: 'max_weight_kg', min_volume_cm3: 'min_volume_m3', max_volume_cm3: 'max_volume_m3',
  });
  const onSubmit = handleSubmit(async (v) => {
    const ok = await run(async () => {
      const res = await save.mutateAsync(ruleFormToPayload(v));
      if (res.warnings.length) {
        setWarnings(res.warnings);
        notify.warning('Regra salva com avisos.');
      } else {
        notify.success('Regra salva');
        onClose();
      }
    });
    void ok;
  });
  return (
    <FormDialog open title={rule ? `Editar regra: ${rule.name}` : 'Nova regra de frete'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error} maxWidth="md" submitLabel="Salvar regra">
      {warnings.map((w) => (
        <Alert key={w} severity="warning">{WARNING_TEXT[w] ?? w}</Alert>
      ))}
      <Grid container spacing={3}>
        <Grid size={{ xs: 12, md: 4 }}>
          <RHFSelect control={control} name="method_id" label="Método" required options={ruleMethods.map((m) => ({ value: m.id, label: m.name }))} />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <RHFSelect control={control} name="zone_id" label="Zona" emptyLabel="Qualquer destino (global)" options={zones.map((z) => ({ value: z.id, label: z.name }))} />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <RHFIntField control={control} name="priority" label="Prioridade" required helperText="Menor número = avaliada primeiro" />
        </Grid>
        <Grid size={12}>
          <RHFTextField control={control} name="name" label="Nome" required maxLength={150} />
        </Grid>
        <Grid size={12}>
          <Divider textAlign="left"><Typography variant="overline">Condições (todas precisam casar; vazio = sem limite)</Typography></Divider>
        </Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFQuantityField control={control} name="min_weight_kg" label="Peso ≥" unit="kg" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFQuantityField control={control} name="max_weight_kg" label="Peso ≤" unit="kg" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFMoneyField control={control} name="min_subtotal_cents" label="Subtotal ≥" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFMoneyField control={control} name="max_subtotal_cents" label="Subtotal ≤" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFQuantityField control={control} name="min_volume_m3" label="Volume ≥" unit="m³" decimals={6} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFQuantityField control={control} name="max_volume_m3" label="Volume ≤" unit="m³" decimals={6} /></Grid>
        <Grid size={{ xs: 12, md: 6 }}><RHFQuantityField control={control} name="max_package_length_cm" label="Maior dimensão ≤" unit="cm" decimals={1} /></Grid>
        <Grid size={12}>
          <Divider textAlign="left"><Typography variant="overline">Preço e prazo</Typography></Divider>
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <RHFSelect control={control} name="price_type" label="Tipo de preço" required options={Object.entries(SHIPPING_PRICE_TYPE).map(([value, label]) => ({ value, label }))} />
        </Grid>
        {(type === 'fixed' || type === 'fixed_plus_per_kg') && <Grid size={{ xs: 12, md: 4 }}><RHFMoneyField control={control} name="price_cents" label={type === 'fixed' ? 'Preço' : 'Parte fixa'} required /></Grid>}
        {(type === 'per_kg' || type === 'fixed_plus_per_kg') && <Grid size={{ xs: 12, md: 4 }}><RHFMoneyField control={control} name="per_kg_cents" label="Por kg (iniciado)" required /></Grid>}
        {type === 'percentage_of_subtotal' && <Grid size={{ xs: 12, md: 4 }}><RHFPercentField control={control} name="percentage_bp" label="% do subtotal" /></Grid>}
        {type !== 'free' && (
          <>
            <Grid size={{ xs: 6, md: 3 }}><RHFMoneyField control={control} name="min_price_cents" label="Piso" /></Grid>
            <Grid size={{ xs: 6, md: 3 }}><RHFMoneyField control={control} name="max_price_cents" label="Teto" /></Grid>
          </>
        )}
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="delivery_days_min" label="Prazo mín." unit="dias úteis" /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><RHFIntField control={control} name="delivery_days_max" label="Prazo máx." unit="dias úteis" /></Grid>
        <Grid size={{ xs: 12, md: 3 }}><RHFDateTimeField control={control} name="valid_from" label="Vigência de" /></Grid>
        <Grid size={{ xs: 12, md: 3 }}><RHFDateTimeField control={control} name="valid_until" label="Vigência até" /></Grid>
        <Grid size={12}><RHFSwitch control={control} name="is_active" label="Ativa" /></Grid>
        <Grid size={12}><RulePreview v={values} methods={methods} zones={zones} /></Grid>
      </Grid>
    </FormDialog>
  );
}
