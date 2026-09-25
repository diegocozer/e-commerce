import SearchIcon from '@mui/icons-material/SearchOutlined';
import { Button, InputAdornment, MenuItem, Stack, TextField } from '@mui/material';
import { useEffect, useState, type ReactNode } from 'react';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';

interface Props {
  q: string;
  onSearch: (q: string) => void;
  placeholder?: string;
  children?: ReactNode;
  onClear?: () => void;
  activeCount?: number;
  right?: ReactNode;
}

/** Barra de busca (debounce 300 ms) + filtros + "Limpar" (UX §5.4). */
export function ListToolbar({ q, onSearch, placeholder = 'Buscar…', children, onClear, activeCount = 0, right }: Props) {
  const [text, setText] = useState(q);
  const debounced = useDebouncedValue(text, 300);
  useEffect(() => setText(q), [q]);
  useEffect(() => {
    if (debounced !== q) onSearch(debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  return (
    <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mb: 3, alignItems: { md: 'center' }, flexWrap: 'wrap', rowGap: 2 }}>
      <TextField
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder={placeholder}
        sx={{ maxWidth: { md: 320 } }}
        slotProps={{
          input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" aria-hidden /></InputAdornment> },
          htmlInput: { 'aria-label': placeholder, type: 'search' },
        }}
      />
      {children}
      {onClear && activeCount > 0 && (
        <Button onClick={onClear} size="small">
          Limpar
        </Button>
      )}
      {right && <Stack direction="row" spacing={2} sx={{ ml: { md: 'auto !important' } }}>{right}</Stack>}
    </Stack>
  );
}

/** Select de filtro compacto; valor "" = todos. */
export function FilterSelect({ label, value, onChange, options, width = 180 }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; width?: number }) {
  return (
    <TextField select label={label} value={value} onChange={(e) => onChange(e.target.value)} sx={{ width: { md: width } }}>
      <MenuItem value="">Todos</MenuItem>
      {options.map((o) => (
        <MenuItem key={o.value} value={o.value}>
          {o.label}
        </MenuItem>
      ))}
    </TextField>
  );
}
