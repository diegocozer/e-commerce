import { Box, CircularProgress } from '@mui/material';
import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useMe } from '@/shared/auth';
import { ErrorState } from '@/shared/ui';

/** Sem sessão admin → /entrar?redirect=<rota atual>. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const { data, isPending, error, refetch } = useMe();
  const location = useLocation();
  if (isPending) {
    return (
      <Box sx={{ display: 'grid', placeItems: 'center', minHeight: '100vh' }} aria-busy="true">
        <CircularProgress aria-label="Carregando sessão" />
      </Box>
    );
  }
  if (error) {
    return (
      <Box sx={{ p: 8 }}>
        <ErrorState error={error} onRetry={() => void refetch()} />
      </Box>
    );
  }
  if (!data) {
    const redirect = `${location.pathname}${location.search}`;
    return <Navigate to={`/entrar?redirect=${encodeURIComponent(redirect)}`} replace />;
  }
  return <>{children}</>;
}
