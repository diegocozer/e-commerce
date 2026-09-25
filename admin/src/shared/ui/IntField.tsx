import { InputAdornment, TextField } from '@mui/material';

export interface IntFieldProps {
  label: string;
  value: number | null;
  onChange: (v: number | null) => void;
  onBlur?: () => void;
  name?: string;
  unit?: string;
  error?: boolean;
  helperText?: string;
  required?: boolean;
  disabled?: boolean;
  inputRef?: React.Ref<HTMLInputElement>;
  min?: number;
}

/** Inteiro (dias, gramas, prioridade…); vazio = null. */
export function IntField({ label, value, onChange, onBlur, name, unit, error, helperText, required, disabled, inputRef, min }: IntFieldProps) {
  return (
    <TextField
      name={name}
      label={label}
      value={value === null || value === undefined ? '' : String(value)}
      onChange={(e) => {
        const raw = e.target.value.replace(/[^\d-]/g, '');
        onChange(raw === '' || raw === '-' ? null : Number(raw));
      }}
      onBlur={onBlur}
      error={error}
      helperText={helperText}
      required={required}
      disabled={disabled}
      inputRef={inputRef}
      slotProps={{
        input: { endAdornment: unit ? <InputAdornment position="end">{unit}</InputAdornment> : undefined },
        htmlInput: { inputMode: 'numeric', className: 'num', min, 'aria-required': required },
      }}
    />
  );
}
