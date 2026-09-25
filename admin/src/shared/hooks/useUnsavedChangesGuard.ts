import { useEffect } from 'react';
import { useBlocker } from 'react-router-dom';

/**
 * Guarda de saída com formulário sujo (UX §5.5): bloqueia navegação interna
 * (o chamador mostra o Dialog) e registra `beforeunload` para fechar a aba.
 */
export function useUnsavedChangesGuard(when: boolean) {
  const blocker = useBlocker(({ currentLocation, nextLocation }) => when && currentLocation.pathname !== nextLocation.pathname);
  useEffect(() => {
    if (!when) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [when]);
  return blocker;
}
