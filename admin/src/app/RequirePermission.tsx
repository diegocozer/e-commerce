import type { ReactNode } from 'react';
import { useCan, type PermissionCheck } from '@/shared/auth';
import { ForbiddenPage } from './pages/ForbiddenPage';

/** Rota sem permissão de ver → página 403 (UX §5.12). O backend continua sendo a autoridade. */
export function RequirePermission({ perm, children }: { perm: PermissionCheck; children: ReactNode }) {
  return useCan(perm) ? <>{children}</> : <ForbiddenPage />;
}
