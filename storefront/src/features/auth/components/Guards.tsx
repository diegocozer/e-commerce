import Box from '@mui/material/Box';
import CircularProgress from '@mui/material/CircularProgress';
import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useAuth } from '../hooks/useAuth';

/** Aceita apenas caminhos internos (UX §4.8.1: ignorar URLs absolutas). */
export function safeRedirect(value: string | null | undefined, fallback = '/conta'): string {
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return fallback;
  return value;
}

function Loading() {
  return (
    <Box sx={{ display: 'grid', placeItems: 'center', minHeight: 240 }} aria-busy="true">
      <CircularProgress aria-label="Carregando" />
    </Box>
  );
}

/** /checkout* e /conta* — visitante vai para /entrar?redirect=<rota atual>. */
export function RequireCustomer({ children }: { children: ReactNode }) {
  const { customer, isLoading } = useAuth();
  const location = useLocation();
  if (isLoading) return <Loading />;
  if (!customer) {
    const redirect = encodeURIComponent(location.pathname + location.search);
    return <Navigate to={`/entrar?redirect=${redirect}`} replace />;
  }
  return <>{children}</>;
}

/** /entrar, /cadastro… — cliente logado vai para a conta (ou redirect). */
export function GuestOnly({ children }: { children: ReactNode }) {
  const { customer, isLoading } = useAuth();
  const location = useLocation();
  if (isLoading) return <Loading />;
  if (customer) {
    const params = new URLSearchParams(location.search);
    return <Navigate to={safeRedirect(params.get('redirect'))} replace />;
  }
  return <>{children}</>;
}
