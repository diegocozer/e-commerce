import AddIcon from '@mui/icons-material/AddOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import { Alert, Button, IconButton, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useEffect, useMemo, useState } from 'react';
import { isApiError, errorMessage } from '@/shared/api/errors';
import type { AdminVariant, SaleUnit } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { formatBRL } from '@/shared/formatters/money';
import { formatQuantity, toMilli } from '@/shared/formatters/quantity';
import { MoneyField, notify, QuantityField } from '@/shared/ui';
import { FormSection } from '@/shared/ui/form';
import { usePriceTiers, useSaveTiers } from '../api';

interface Row {
  key: number;
  min: string;
  price: number | null;
}

/** Valida faixas como a API (RN-PRC-005): mínimo > 0 e crescente, preço não crescente. */
export function validateTiers(rows: Row[]): Record<number, string> {
  const errs: Record<number, string> = {};
  rows.forEach((r, i) => {
    const m = toMilli(r.min);
    if (m === null || m <= 0) errs[i] = 'Quantidade mínima deve ser maior que zero.';
    else if (i > 0) {
      const prev = toMilli(rows[i - 1].min);
      if (prev !== null && m <= prev) errs[i] = `A faixa ${i + 1} deve começar acima de ${rows[i - 1].min.replace('.', ',')}.`;
    }
    if (!errs[i]) {
      if (r.price === null || r.price <= 0) errs[i] = 'Informe o preço.';
      else if (i > 0 && rows[i - 1].price !== null && r.price > rows[i - 1].price!) errs[i] = 'O preço não pode aumentar com a quantidade.';
    }
  });
  return errs;
}

let seq = 0;

export function PriceTiersSection({ variants, saleUnit }: { variants: AdminVariant[]; saleUnit: SaleUnit }) {
  const canBase = useCan('prices.manage');
  const canList = useCan('pricing.manage');
  const [variantId, setVariantId] = useState<number | null>(variants[0]?.id ?? null);
  const [listId, setListId] = useState<string>('base');
  const tiers = usePriceTiers(variantId);
  const save = useSaveTiers(variantId ?? 0);
  const [rows, setRows] = useState<Row[]>([]);
  const [submitted, setSubmitted] = useState(false);

  const current = useMemo(() => {
    if (!tiers.data) return [];
    return listId === 'base' ? tiers.data.base : (tiers.data.price_lists.find((p) => String(p.price_list.id) === listId)?.tiers ?? []);
  }, [tiers.data, listId]);

  useEffect(() => {
    setRows([...current].sort((a, b) => a.min_quantity - b.min_quantity).map((t) => ({ key: ++seq, min: String(t.min_quantity), price: t.price_cents })));
    setSubmitted(false);
  }, [current]);

  const canEdit = listId === 'base' ? canBase : canList;
  const errs = validateTiers(rows);
  const variant = variants.find((v) => v.id === variantId);

  const onSave = () => {
    setSubmitted(true);
    if (Object.keys(errs).length) return;
    save.mutate(
      { price_list_id: listId === 'base' ? null : Number(listId), tiers: rows.map((r) => ({ min_quantity: Number(r.min), price_cents: r.price! })) },
      {
        onSuccess: () => notify.success('Faixas de preço salvas'),
        onError: (e) => notify.error(isApiError(e) && e.fieldErrors ? Object.values(e.fieldErrors).flat().join(' ') : errorMessage(e)),
      },
    );
  };

  if (variants.length === 0) return null;
  return (
    <FormSection id="faixas" title="Faixas de preço por quantidade" description="Salvas separadamente do produto. O cliente paga sempre o menor preço entre base, tabela, promoção e preço específico.">
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={3} sx={{ mb: 3 }}>
        <TextField select label="Variante" value={variantId ?? ''} onChange={(e) => setVariantId(Number(e.target.value))}>
          {variants.map((v) => (
            <MenuItem key={v.id} value={v.id}>
              {v.sku} — {v.name} ({formatBRL(v.price_cents)})
            </MenuItem>
          ))}
        </TextField>
        <TextField select label="Tabela" value={listId} onChange={(e) => setListId(e.target.value)}>
          <MenuItem value="base">Preço base</MenuItem>
          {tiers.data?.price_lists.map((p) => (
            <MenuItem key={p.price_list.id} value={String(p.price_list.id)}>
              {p.price_list.name}
            </MenuItem>
          ))}
        </TextField>
      </Stack>
      {!canEdit && <Alert severity="info" sx={{ mb: 2 }}>Somente leitura: você não tem permissão para alterar estas faixas.</Alert>}
      <Stack spacing={2}>
        {rows.map((r, i) => (
          <Stack key={r.key} direction="row" spacing={2} sx={{ alignItems: 'flex-start' }}>
            <QuantityField label={`Faixa ${i + 1}: a partir de`} value={r.min} unit={saleUnit === 'SQUARE_METER' ? 'm²' : undefined} disabled={!canEdit} onChange={(v) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, min: v } : x)))} error={submitted && !!errs[i]} helperText={submitted ? errs[i] : undefined} />
            <MoneyField label="Preço unitário" value={r.price} disabled={!canEdit} onChange={(v) => setRows((rs) => rs.map((x) => (x.key === r.key ? { ...x, price: v } : x)))} />
            {canEdit && (
              <IconButton aria-label={`Remover faixa ${i + 1}`} onClick={() => setRows((rs) => rs.filter((x) => x.key !== r.key))}>
                <DeleteIcon />
              </IconButton>
            )}
          </Stack>
        ))}
        {rows.length === 0 && <Typography variant="body2" color="text.secondary">Preço único (sem faixas).</Typography>}
      </Stack>
      {rows.length > 0 && Object.keys(errs).length === 0 && (
        <Typography variant="body2" sx={{ mt: 3 }} className="num">
          Como o cliente verá:{' '}
          {rows.map((r) => `a partir de ${formatQuantity(Number(r.min), saleUnit)} ${formatBRL(r.price)}`).join(' · ')}
          {variant ? ` (base ${formatBRL(variant.price_cents)})` : ''}
        </Typography>
      )}
      {canEdit && (
        <Stack direction="row" spacing={2} sx={{ mt: 3 }}>
          <Button startIcon={<AddIcon />} disabled={rows.length >= 20} onClick={() => setRows((rs) => [...rs, { key: ++seq, min: '', price: null }])}>
            Adicionar faixa
          </Button>
          <Button variant="contained" onClick={onSave} disabled={save.isPending}>
            Salvar faixas
          </Button>
        </Stack>
      )}
    </FormSection>
  );
}
