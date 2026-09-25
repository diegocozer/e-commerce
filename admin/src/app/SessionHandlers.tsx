import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { setForbiddenHandler, setUnauthorizedHandler } from '@/shared/api/client';
import { meKey } from '@/shared/auth';
import { notify } from '@/shared/ui/notify';

/**
 * 401 (sessão expirada: inatividade 30 min / absoluto 8 h) → limpa cache e vai para o login
 * com `redirect` para voltar à rota atual. 403 → snackbar + refetch de permissões (UX §5.12).
 */
export function SessionHandlers() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  useEffect(() => {
    setUnauthorizedHandler(() => {
      const here = `${window.location.pathname.replace(/^\/admin/, '') || '/'}${window.location.search}`;
      if (here.startsWith('/entrar')) return;
      qc.clear();
      qc.setQueryData(meKey, null);
      navigate(`/entrar?expirada=1&redirect=${encodeURIComponent(here)}`, { replace: true });
    });
    setForbiddenHandler(() => {
      notify.error('Você não tem permissão para esta ação.');
      void qc.invalidateQueries({ queryKey: meKey });
    });
    return () => {
      setUnauthorizedHandler(null);
      setForbiddenHandler(null);
    };
  }, [navigate, qc]);
  return null;
}
