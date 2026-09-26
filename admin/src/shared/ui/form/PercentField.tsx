import { InputAdornment, TextField } from '@mui/material';
import { useEffect, useState } from 'react';
import { Controller, type Control, type FieldValues, type Path } from 'react-hook-form';
import { parsePercentToBp } from '@/shared/formatters/money';

/** Percentual digitado ("10,5") ↔ basis points (1050). */
export function RHFPercentField<T extends FieldValues>({ control, name, label, helperText }: { control: Control<T>; name: Path<T>; label: string; helperText?: string }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => <PercentInput label={label} value={field.value as number | null} onChange={field.onChange} error={fieldState.error?.message} helperText={helperText} />}
    />
  );
}

function PercentInput({ label, value, onChange, error, helperText }: { label: string; value: number | null; onChange: (v: number | null) => void; error?: string; helperText?: string }) {
  const toText = (bp: number | null) => (bp === null ? '' : String(bp / 100).replace('.', ','));
  const [text, setText] = useState(toText(value));
  useEffect(() => {
    if (parsePercentToBp(text) !== value) setText(toText(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);
  return (
    <TextField
      label={label}
      value={text}
      onChange={(e) => {
        setText(e.target.value);
        onChange(e.target.value === '' ? null : parsePercentToBp(e.target.value));
      }}
      error={!!error}
      helperText={error ?? helperText}
      slotProps={{ input: { endAdornment: <InputAdornment position="end">%</InputAdornment> }, htmlInput: { inputMode: 'decimal', className: 'num' } }}
    />
  );
}
