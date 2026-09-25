import { InputAdornment, TextField } from '@mui/material';
import { useEffect, useState, type FocusEvent } from 'react';
import type { Cents } from '@/shared/api/types';
import { centsToInput, parseBRLToCents } from '@/shared/formatters/money';

export interface MoneyFieldProps {
  label: string;
  value: Cents | null;
  onChange: (cents: Cents | null) => void;
  onBlur?: () => void;
  name?: string;
  error?: boolean;
  helperText?: string;
  required?: boolean;
  disabled?: boolean;
  allowNegative?: boolean;
  suffix?: string;
  inputRef?: React.Ref<HTMLInputElement>;
  id?: string;
}

/**
 * Campo BRL: o usuário digita "1.234,56" e o valor é emitido em **centavos inteiros**
 * (parse por string, sem float — UX §5.5 / ARCHITECTURE §8.6).
 */
export function MoneyField({ label, value, onChange, onBlur, name, error, helperText, required, disabled, allowNegative, suffix, inputRef, id }: MoneyFieldProps) {
  const [text, setText] = useState(centsToInput(value));
  const [invalid, setInvalid] = useState(false);

  useEffect(() => {
    // Sincroniza quando o valor muda por fora (reset do formulário).
    const parsed = parseBRLToCents(text);
    if (parsed !== value) setText(centsToInput(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const handleChange = (raw: string) => {
    const cleaned = raw.replace(allowNegative ? /[^\d.,-]/g : /[^\d.,]/g, '');
    setText(cleaned);
    if (cleaned.trim() === '') {
      setInvalid(false);
      onChange(null);
      return;
    }
    const cents = parseBRLToCents(cleaned);
    setInvalid(cents === null);
    if (cents !== null) onChange(cents);
  };

  const handleBlur = (_e: FocusEvent<HTMLInputElement>) => {
    const cents = parseBRLToCents(text);
    if (cents !== null) setText(centsToInput(cents));
    onBlur?.();
  };

  return (
    <TextField
      id={id}
      name={name}
      label={label}
      value={text}
      required={required}
      disabled={disabled}
      error={error || invalid}
      helperText={invalid ? 'Valor inválido. Use o formato 1.234,56.' : helperText}
      onChange={(e) => handleChange(e.target.value)}
      onBlur={handleBlur}
      inputRef={inputRef}
      slotProps={{
        input: {
          startAdornment: <InputAdornment position="start">R$</InputAdornment>,
          endAdornment: suffix ? <InputAdornment position="end">{suffix}</InputAdornment> : undefined,
        },
        htmlInput: { inputMode: 'decimal', className: 'num', 'aria-required': required },
      }}
    />
  );
}
