import { useEffect } from 'react';

/** "Pedidos · Comunika Painel" (UX §5.1). */
export function usePageTitle(title: string): void {
  useEffect(() => {
    document.title = `${title} · Comunika Painel`;
  }, [title]);
}
