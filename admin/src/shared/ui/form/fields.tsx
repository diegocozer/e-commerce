import {
  Checkbox,
  FormControlLabel,
  FormHelperText,
  MenuItem,
  Switch,
  TextField,
  type TextFieldProps,
} from '@mui/material';
import type { ReactNode } from 'react';
import { Controller, type Control, type FieldValues, type Path } from 'react-hook-form';
import { IntField } from '../IntField';
import { MoneyField } from '../MoneyField';
import { QuantityField } from '../QuantityField';

interface Base<T extends FieldValues> {
  control: Control<T>;
  name: Path<T>;
  label: string;
  required?: boolean;
  disabled?: boolean;
  helperText?: ReactNode;
}

type TextExtra = Pick<TextFieldProps, 'type' | 'multiline' | 'minRows' | 'maxRows' | 'placeholder' | 'autoComplete' | 'autoFocus' | 'slotProps'>;

export function RHFTextField<T extends FieldValues>({ control, name, label, required, disabled, helperText, maxLength, onValueChange, ...rest }: Base<T> & TextExtra & { maxLength?: number; onValueChange?: (v: string) => void }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => {
        const val = (field.value ?? '') as string;
        const counter = maxLength ? `${val.length}/${maxLength}` : null;
        return (
          <TextField
            {...rest}
            name={field.name}
            value={val}
            onChange={(e) => {
              field.onChange(e.target.value);
              onValueChange?.(e.target.value);
            }}
            onBlur={field.onBlur}
            inputRef={field.ref}
            label={label}
            required={required}
            disabled={disabled}
            error={!!fieldState.error}
            helperText={fieldState.error?.message ?? (counter ? <>{helperText} {counter}</> : helperText)}
          />
        );
      }}
    />
  );
}

export function RHFSelect<T extends FieldValues, V extends string | number>({
  control,
  name,
  label,
  required,
  disabled,
  helperText,
  options,
  emptyLabel,
}: Base<T> & { options: { value: V; label: string }[]; emptyLabel?: string }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <TextField
          select
          name={field.name}
          value={field.value ?? ''}
          onChange={(e) => {
            const raw = e.target.value;
            const opt = options.find((o) => String(o.value) === String(raw));
            field.onChange(raw === '' ? null : (opt?.value ?? raw));
          }}
          onBlur={field.onBlur}
          inputRef={field.ref}
          label={label}
          required={required}
          disabled={disabled}
          error={!!fieldState.error}
          helperText={fieldState.error?.message ?? helperText}
        >
          {emptyLabel !== undefined && (
            <MenuItem value="">
              <em>{emptyLabel}</em>
            </MenuItem>
          )}
          {options.map((o) => (
            <MenuItem key={String(o.value)} value={o.value}>
              {o.label}
            </MenuItem>
          ))}
        </TextField>
      )}
    />
  );
}

export function RHFSwitch<T extends FieldValues>({ control, name, label, disabled, helperText }: Base<T>) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <div>
          <FormControlLabel
            control={<Switch checked={!!field.value} onChange={(e) => field.onChange(e.target.checked)} inputRef={field.ref} disabled={disabled} />}
            label={label}
          />
          {(fieldState.error || helperText) && <FormHelperText error={!!fieldState.error}>{fieldState.error?.message ?? helperText}</FormHelperText>}
        </div>
      )}
    />
  );
}

export function RHFCheckbox<T extends FieldValues>({ control, name, label, disabled, helperText }: Base<T>) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <div>
          <FormControlLabel
            control={<Checkbox checked={!!field.value} onChange={(e) => field.onChange(e.target.checked)} inputRef={field.ref} disabled={disabled} />}
            label={label}
          />
          {(fieldState.error || helperText) && <FormHelperText error={!!fieldState.error}>{fieldState.error?.message ?? helperText}</FormHelperText>}
        </div>
      )}
    />
  );
}

export function RHFMoneyField<T extends FieldValues>({ control, name, label, required, disabled, helperText, suffix }: Base<T> & { suffix?: string }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <MoneyField
          name={field.name}
          label={label}
          value={(field.value ?? null) as number | null}
          onChange={field.onChange}
          onBlur={field.onBlur}
          inputRef={field.ref}
          required={required}
          disabled={disabled}
          suffix={suffix}
          error={!!fieldState.error}
          helperText={fieldState.error?.message ?? (helperText as string | undefined)}
        />
      )}
    />
  );
}

export function RHFQuantityField<T extends FieldValues>({ control, name, label, required, disabled, helperText, unit, decimals }: Base<T> & { unit?: string; decimals?: number }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <QuantityField
          name={field.name}
          label={label}
          value={(field.value ?? '') as string}
          onChange={field.onChange}
          onBlur={field.onBlur}
          inputRef={field.ref}
          unit={unit}
          decimals={decimals}
          required={required}
          disabled={disabled}
          error={!!fieldState.error}
          helperText={fieldState.error?.message ?? (helperText as string | undefined)}
        />
      )}
    />
  );
}

export function RHFIntField<T extends FieldValues>({ control, name, label, required, disabled, helperText, unit }: Base<T> & { unit?: string }) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <IntField
          name={field.name}
          label={label}
          value={(field.value ?? null) as number | null}
          onChange={field.onChange}
          onBlur={field.onBlur}
          inputRef={field.ref}
          unit={unit}
          required={required}
          disabled={disabled}
          error={!!fieldState.error}
          helperText={fieldState.error?.message ?? (helperText as string | undefined)}
        />
      )}
    />
  );
}

/** datetime-local em horário de SP; valor do formulário = string "YYYY-MM-DDTHH:mm" ou "". */
export function RHFDateTimeField<T extends FieldValues>({ control, name, label, required, disabled, helperText }: Base<T>) {
  return (
    <Controller
      control={control}
      name={name}
      render={({ field, fieldState }) => (
        <TextField
          type="datetime-local"
          name={field.name}
          value={field.value ?? ''}
          onChange={(e) => field.onChange(e.target.value)}
          onBlur={field.onBlur}
          inputRef={field.ref}
          label={label}
          required={required}
          disabled={disabled}
          error={!!fieldState.error}
          helperText={fieldState.error?.message ?? helperText}
          slotProps={{ inputLabel: { shrink: true } }}
        />
      )}
    />
  );
}
