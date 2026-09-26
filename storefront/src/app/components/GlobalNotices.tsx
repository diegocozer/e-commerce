import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link as RouterLink } from 'react-router';
import { authKeys } from '@/features/auth';
import { CART_EXPIRED_EVENT, UNAUTHENTICATED_EVENT } from '@/shared/api/client';
import { useOnline } from '@/shared/hooks/useOnline';
import { storageGet, storageSet } from '@/shared/lib/storage';
import { useSnackbar } from '@/shared/ui/Snackbar';

/** Eventos globais do cliente HTTP: carrinho expirado e sessão expirada (UX §6.8). */
export function HttpEventListeners() {
  const notify = useSnackbar();
  const qc = useQueryClient();
  useEffect(() => {
    const onCart = () => notify('Seu carrinho anterior expirou.', { severity: 'info' });
    const onUnauth = (e: Event) => {
      const url = (e as CustomEvent<{ url?: string }>).detail?.url ?? '';
      if (url === '/me') return;
      if (qc.getQueryData(authKeys.me)) {
        qc.setQueryData(authKeys.me, null);
        notify('Sua sessão expirou. Entre novamente para continuar.', { severity: 'warning' });
      }
    };
    window.addEventListener(CART_EXPIRED_EVENT, onCart);
    window.addEventListener(UNAUTHENTICATED_EVENT, onUnauth);
    return () => {
      window.removeEventListener(CART_EXPIRED_EVENT, onCart);
      window.removeEventListener(UNAUTHENTICATED_EVENT, onUnauth);
    };
  }, [notify, qc]);
  return null;
}

export function OfflineBar() {
  const online = useOnline();
  if (online) return null;
  return (
    <Box role="status" sx={{ bgcolor: 'warning.main', color: '#fff', textAlign: 'center', py: 1, fontSize: 14 }}>
      Você está sem conexão. Tentaremos novamente quando voltar.
    </Box>
  );
}

const CONSENT_KEY = 'cv_cookie_consent';

export function CookieBanner() {
  const [open, setOpen] = useState(() => !storageGet(CONSENT_KEY));
  if (!open) return null;
  const choose = (v: 'all' | 'essential') => {
    storageSet(CONSENT_KEY, v);
    setOpen(false);
  };
  return (
    <Paper elevation={8} role="region" aria-label="Aviso de cookies" sx={{ position: 'fixed', left: 16, right: 16, bottom: 16, zIndex: 20, p: 4, maxWidth: 720, mx: 'auto', display: 'flex', flexWrap: 'wrap', gap: 3, alignItems: 'center' }}>
      <Typography variant="body2" sx={{ flex: 1, minWidth: 240 }}>
        Usamos cookies essenciais para o funcionamento da loja e, com seu consentimento, para análise de uso.
      </Typography>
      <Button size="medium" onClick={() => choose('all')}>Aceitar</Button>
      <Button size="medium" variant="outlined" onClick={() => choose('essential')}>Somente essenciais</Button>
      <Button size="medium" variant="text" component={RouterLink} to="/institucional/privacidade">Saiba mais</Button>
    </Paper>
  );
}
