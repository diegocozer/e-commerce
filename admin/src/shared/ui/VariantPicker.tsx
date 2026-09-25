import { Autocomplete, TextField } from '@mui/material';
import { useState } from 'react';
import { useVariantPicker } from '@/shared/api/lookups';
import type { AdminVariantPickerItem } from '@/shared/api/types';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';

/** Autocomplete de variantes (GET /admin/variants). */
export function VariantPicker({ value, onChange, label = 'Variante', error, helperText, required }: { value: AdminVariantPickerItem | null; onChange: (v: AdminVariantPickerItem | null) => void; label?: string; error?: boolean; helperText?: string; required?: boolean }) {
  const [input, setInput] = useState('');
  const q = useDebouncedValue(input, 300);
  const { data = [], isFetching } = useVariantPicker(q);
  return (
    <Autocomplete
      value={value}
      onChange={(_, v) => onChange(v)}
      inputValue={input}
      onInputChange={(_, v) => setInput(v)}
      options={data}
      loading={isFetching}
      filterOptions={(x) => x}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      getOptionLabel={(o) => `${o.sku} — ${o.product.name}${o.name && o.name !== 'Padrão' ? ` · ${o.name}` : ''}`}
      noOptionsText="Nenhuma variante encontrada"
      loadingText="Buscando…"
      renderInput={(params) => <TextField {...params} label={label} required={required} error={error} helperText={helperText} placeholder="Busque por SKU ou nome" />}
    />
  );
}
