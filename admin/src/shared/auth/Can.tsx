import type { ReactNode } from 'react';
import type { PermissionCheck } from './me';
import { useCan } from './useCan';

/** Renderiza os filhos só com permissão — ações sem permissão não são renderizadas (UX §5.12). */
export function Can({ perm, children, fallback = null }: { perm: PermissionCheck; children: ReactNode; fallback?: ReactNode }) {
  return <>{useCan(perm) ? children : fallback}</>;
}
