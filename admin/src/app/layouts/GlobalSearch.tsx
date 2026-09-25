import SearchIcon from '@mui/icons-material/SearchOutlined';
import { Button, Dialog, DialogContent, InputAdornment, List, ListItemButton, ListItemText, TextField } from '@mui/material';
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCanFn } from '@/shared/auth';

/** Ctrl/⌘+K: busca pedidos por número, clientes, produtos (UX §5.1) — abre a lista filtrada. */
export function GlobalSearch() {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const navigate = useNavigate();
  const can = useCanFn();
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);
  const targets = [
    { label: 'Pedidos', to: '/pedidos', ok: can('orders.view') },
    { label: 'Clientes', to: '/clientes', ok: can('customers.view') },
    { label: 'Produtos', to: '/produtos', ok: can('products.view') },
  ].filter((t) => t.ok);
  const go = (to: string) => {
    navigate(`${to}?q=${encodeURIComponent(q.trim())}`);
    setOpen(false);
    setQ('');
  };
  if (targets.length === 0) return null;
  return (
    <>
      <Button
        color="inherit"
        onClick={() => setOpen(true)}
        startIcon={<SearchIcon />}
        sx={{ color: 'text.secondary', border: 1, borderColor: 'divider', px: 3, display: { xs: 'none', md: 'inline-flex' } }}
      >
        Buscar (Ctrl+K)
      </Button>
      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm" aria-label="Busca global">
        <DialogContent>
          <TextField
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && q.trim() && targets[0]) go(targets[0].to);
            }}
            placeholder="Número do pedido, cliente, CPF/CNPJ, e-mail, produto ou SKU"
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> }, htmlInput: { 'aria-label': 'Buscar' } }}
          />
          {q.trim() && (
            <List>
              {targets.map((t) => (
                <ListItemButton key={t.to} onClick={() => go(t.to)}>
                  <ListItemText primary={`Buscar "${q.trim()}" em ${t.label}`} />
                </ListItemButton>
              ))}
            </List>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
