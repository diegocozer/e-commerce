import { InputAdornment, TextField } from '@mui/material';
import { useEffect, useState } from 'react';
import { decimalToInput, parseDecimal } from '@/shared/formatters/quantity';

export interface QuantityFieldProps {
  label: string;
  /** Valor normalizado para a API ("1.25") ou "" quando vazio. */
  value: string;
  onChange: (value: string) => void;
  onBlur?: () => void;
  name?: string;
  unit?: string;
  /** Casas decimais aceitas (3 = padrão da API; 0 = inteiro). */
  decimals?: number;
  error?: boolean;
  helperText?: string;
  required?: boolean;
  disabled?: boolean;
  inputRef?: React.Ref<HTMLInputElement>;
  id?: string;
  size?: 'small' | 'medium';
}

/** Entrada decimal pt-BR ("1,5") com unidade como adornment; emite string normalizada ("1.5"). */
export function QuantityField({ label, value, onChange, onBlur, name, unit, decimals = 3, error, helperText, required, disabled, inputRef, id }: QuantityFieldProps) {
  const [text, setText] = useState(decimalToInput(value));
  const [invalid, setInvalid] = useState(false);

  useEffect(() => {
    if (parseDecimal(text, decimals) !== (value || null)) setText(decimalToInput(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const handleChange = (raw: string) => {
    const cleaned = raw.replace(/[^\d.,]/g, '');
    setText(cleaned);
    if (cleaned === '') {
      setInvalid(false);
      onChange('');
      return;
    }
    const norm = parseDecimal(cleaned, decimals);
    setInvalid(norm === null);
    if (norm !== null) onChange(norm);
  };

  const invalidMsg = decimals === 0 ? 'Informe um número inteiro.' : `Use no máximo ${decimals} casas decimais.`;
  return (
    <TextField
      id={id}
      name={name}
      label={label}
      value={text}
      required={required}
      disabled={disabled}
      error={error || invalid}
      helperText={invalid ? invalidMsg : helperText}
      onChange={(e) => handleChange(e.target.value)}
      onBlur={() => {
        const norm = parseDecimal(text, decimals);
        if (norm !== null) setText(decimalToInput(norm));
        onBlur?.();
      }}
      inputRef={inputRef}
      slotProps={{
        input: { endAdornment: unit ? <InputAdornment position="end">{unit}</InputAdornment> : undefined },
        htmlInput: { inputMode: decimals === 0 ? 'numeric' : 'decimal', className: 'num', 'aria-required': required },
      }}
    />
  );
}
