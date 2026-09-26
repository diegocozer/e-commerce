import Visibility from '@mui/icons-material/Visibility';
import VisibilityOff from '@mui/icons-material/VisibilityOff';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import TextField, { type TextFieldProps } from '@mui/material/TextField';
import { forwardRef, useState } from 'react';

export const PasswordField = forwardRef<HTMLInputElement, TextFieldProps & { autoComplete: 'current-password' | 'new-password' }>(function PasswordField(
  { autoComplete, ...props },
  ref,
) {
  const [show, setShow] = useState(false);
  return (
    <TextField
      {...props}
      inputRef={ref}
      type={show ? 'text' : 'password'}
      slotProps={{
        htmlInput: { autoComplete, maxLength: 72 },
        input: {
          endAdornment: (
            <InputAdornment position="end">
              <IconButton aria-label={show ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={show} onClick={() => setShow((s) => !s)} edge="end">
                {show ? <VisibilityOff /> : <Visibility />}
              </IconButton>
            </InputAdornment>
          ),
        },
      }}
    />
  );
});
