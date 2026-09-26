import { Autocomplete, TextField } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '@/shared/api/client';
import type { Paginated } from '@/shared/api/types';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';

interface Option {
  id: number;
  label: string;
}

/** Autocomplete remoto genérico (clientes/empresas). */
export function EntityAutocomplete<T>({ path, label, toOption, value, onChange, error, required }: { path: string; label: string; toOption: (row: T) => Option; value: Option | null; onChange: (o: Option | null) => void; error?: string; required?: boolean }) {
  const [input, setInput] = useState('');
  const q = useDebouncedValue(input, 300);
  const { data = [], isFetching } = useQuery({ queryKey: ['admin', 'lookup', path, q], queryFn: () => api.get<Paginated<T>>(path, { q: q || undefined, per_page: 20 }), select: (r) => r.data.map(toOption) });
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
      getOptionLabel={(o) => o.label}
      noOptionsText="Nada encontrado"
      renderInput={(params) => <TextField {...params} label={label} required={required} error={!!error} helperText={error} />}
    />
  );
}
