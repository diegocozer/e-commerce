import { Alert, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, Stack } from '@mui/material';
import type { FormEvent, ReactNode } from 'react';

interface Props {
  open: boolean;
  title: string;
  onClose: () => void;
  onSubmit: (e?: FormEvent) => void;
  submitting?: boolean;
  submitLabel?: string;
  error?: string | null;
  maxWidth?: 'xs' | 'sm' | 'md' | 'lg';
  readOnly?: boolean;
  children: ReactNode;
}

/** Formulário em Dialog (CRUDs simples). Clique fora não fecha com envio em andamento. */
export function FormDialog({ open, title, onClose, onSubmit, submitting, submitLabel = 'Salvar', error, maxWidth = 'sm', readOnly, children }: Props) {
  return (
    <Dialog open={open} onClose={submitting ? undefined : onClose} fullWidth maxWidth={maxWidth} aria-labelledby="form-dialog-title">
      <form noValidate onSubmit={onSubmit}>
        <DialogTitle id="form-dialog-title">{title}</DialogTitle>
        <DialogContent>
          <Stack spacing={4} sx={{ pt: 1 }}>
            {error && (
              <Alert severity="error" role="alert">
                {error}
              </Alert>
            )}
            {children}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose} disabled={submitting}>
            {readOnly ? 'Fechar' : 'Cancelar'}
          </Button>
          {!readOnly && (
            <Button type="submit" variant="contained" disabled={submitting} startIcon={submitting ? <CircularProgress size={14} color="inherit" /> : undefined}>
              {submitLabel}
            </Button>
          )}
        </DialogActions>
      </form>
    </Dialog>
  );
}
