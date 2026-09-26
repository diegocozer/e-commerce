import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Link from '@mui/material/Link';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState, type FormEvent } from 'react';
import { useAddresses } from '@/features/account';
import { useAuth } from '@/features/auth';
import { formatCEP, isValidCEP, onlyDigits } from '@/shared/formatters/postalCode';
import { usePreferredPostalCode } from '@/shared/hooks/usePostalCode';

function SavedAddresses({ onPick }: { onPick: (cep: string) => void }) {
  const q = useAddresses();
  if (!q.data?.length) return null;
  return (
    <>
      <Typography variant="overline" component="h3" sx={{ mt: 3 }}>Endereços salvos</Typography>
      <List dense>
        {q.data.map((a) => (
          <ListItemButton key={a.uuid} onClick={() => onPick(a.postal_code)}>
            <ListItemText primary={a.label ?? formatCEP(a.postal_code)} secondary={a.formatted} />
          </ListItemButton>
        ))}
      </List>
    </>
  );
}

/** Chip de CEP (UX §3.2): pré-preenche frete no produto, carrinho e checkout. */
export function CepDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [cep, setCep] = usePreferredPostalCode();
  const { isAuthenticated } = useAuth();
  const [value, setValue] = useState(formatCEP(cep ?? ''));
  const [error, setError] = useState<string | null>(null);
  const save = (e?: FormEvent) => {
    e?.preventDefault();
    if (!isValidCEP(value)) return setError('CEP inválido. Use 8 dígitos, ex.: 89010-000');
    setCep(onlyDigits(value));
    onClose();
  };
  return (
    <Dialog open={open} onClose={onClose} aria-labelledby="cep-title" fullWidth maxWidth="xs">
      <DialogTitle id="cep-title">Informe seu CEP</DialogTitle>
      <DialogContent>
        <form onSubmit={save} noValidate>
          <TextField autoFocus label="CEP" value={value} onChange={(e) => setValue(formatCEP(e.target.value))} error={Boolean(error)} helperText={error ?? undefined} sx={{ mt: 1 }} slotProps={{ htmlInput: { inputMode: 'numeric', autoComplete: 'postal-code', maxLength: 9 } }} />
        </form>
        <Link href="https://buscacepinter.correios.com.br/" target="_blank" rel="noopener" variant="body2">Não sei meu CEP</Link>
        {isAuthenticated ? <SavedAddresses onPick={(c) => { setCep(c); onClose(); }} /> : null}
      </DialogContent>
      <DialogActions>
        <Button variant="outlined" onClick={onClose}>Cancelar</Button>
        <Button onClick={() => save()}>Salvar CEP</Button>
      </DialogActions>
    </Dialog>
  );
}
