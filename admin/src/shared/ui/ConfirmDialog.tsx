import {
  Button,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  FormControlLabel,
} from '@mui/material';
import { useEffect, useState, type ReactNode } from 'react';

interface Props {
  open: boolean;
  title: string;
  description?: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  destructive?: boolean;
  loading?: boolean;
  /** Checkbox de ciência obrigatório (ações irreversíveis — UX §6.4). */
  acknowledge?: string;
  children?: ReactNode;
  onConfirm: () => void;
  onClose: () => void;
}

/** Foco inicial no botão seguro; Esc volta; clique fora não confirma (UX §6.4). */
export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel,
  cancelLabel = 'Voltar',
  destructive,
  loading,
  acknowledge,
  children,
  onConfirm,
  onClose,
}: Props) {
  const [ack, setAck] = useState(false);
  useEffect(() => {
    if (open) setAck(false);
  }, [open]);
  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} maxWidth="xs" fullWidth aria-labelledby="confirm-title">
      <DialogTitle id="confirm-title">{title}</DialogTitle>
      <DialogContent>
        {description && <DialogContentText component="div">{description}</DialogContentText>}
        {children}
        {acknowledge && (
          <FormControlLabel
            sx={{ mt: 2 }}
            control={<Checkbox checked={ack} onChange={(e) => setAck(e.target.checked)} />}
            label={acknowledge}
          />
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} autoFocus disabled={loading}>
          {cancelLabel}
        </Button>
        <Button
          variant="contained"
          color={destructive ? 'error' : 'primary'}
          onClick={onConfirm}
          disabled={loading || (!!acknowledge && !ack)}
          startIcon={loading ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {confirmLabel}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
