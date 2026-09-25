import Add from '@mui/icons-material/Add';
import Remove from '@mui/icons-material/Remove';
import Box from '@mui/material/Box';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import type { KeyboardEvent, ReactNode } from 'react';
import { parseDecimal } from '../saleUnit/decimal';

interface Props {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  onStep: (direction: 1 | -1, factor?: number) => void;
  onBoundary?: (edge: 'min' | 'max') => void;
  min: number;
  max: number | null;
  /** "5 metros" — anunciado como aria-valuetext. */
  valueText: string;
  adornment?: string;
  integer?: boolean;
  productName: string;
  error?: ReactNode;
  helperText?: ReactNode;
  disabled?: boolean;
  onBlur?: () => void;
  size?: 'small' | 'medium';
}

/**
 * Stepper acessível (UX §6.10): input text com role=spinbutton, inputMode decimal/numeric,
 * setas ↑/↓ (passo), PageUp/PageDown (10×), Home/End (mín/máx); botões 44×44 que não repetem.
 */
export function QuantityStepper(props: Props) {
  const { id, label, value, onChange, onStep, onBoundary, min, max, valueText, adornment, integer, productName, error, helperText, disabled, onBlur, size } = props;
  const parsed = parseDecimal(value, 3);
  const numeric = parsed.ok ? parsed.milli / 1000 : null;
  const atMin = numeric !== null && numeric <= min;
  const atMax = numeric !== null && max !== null && numeric >= max;

  const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowUp') { e.preventDefault(); onStep(1); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); onStep(-1); }
    else if (e.key === 'PageUp') { e.preventDefault(); onStep(1, 10); }
    else if (e.key === 'PageDown') { e.preventDefault(); onStep(-1, 10); }
    else if (e.key === 'Home' && onBoundary) { e.preventDefault(); onBoundary('min'); }
    else if (e.key === 'End' && onBoundary && max !== null) { e.preventDefault(); onBoundary('max'); }
  };

  return (
    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2 }}>
      <IconButton
        aria-label={`Diminuir quantidade de ${productName}`}
        onClick={() => onStep(-1)}
        disabled={disabled || atMin}
        sx={{ border: 1, borderColor: 'border.input', mt: size === 'small' ? 0 : 1 }}
      >
        <Remove />
      </IconButton>
      <TextField
        id={id}
        label={label}
        value={value}
        size={size}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
        error={Boolean(error)}
        helperText={error ?? helperText}
        sx={{ maxWidth: 180 }}
        slotProps={{
          htmlInput: {
            role: 'spinbutton',
            inputMode: integer ? 'numeric' : 'decimal',
            'aria-valuemin': min,
            'aria-valuemax': max ?? undefined,
            'aria-valuenow': numeric ?? undefined,
            'aria-valuetext': valueText,
            autoComplete: 'off',
            onKeyDown,
            className: 'num',
          },
          input: adornment ? { endAdornment: <InputAdornment position="end">{adornment}</InputAdornment> } : undefined,
        }}
      />
      <IconButton
        aria-label={`Aumentar quantidade de ${productName}`}
        onClick={() => onStep(1)}
        disabled={disabled || atMax}
        sx={{ border: 1, borderColor: 'border.input', mt: size === 'small' ? 0 : 1 }}
      >
        <Add />
      </IconButton>
    </Box>
  );
}
