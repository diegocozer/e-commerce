import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { useState } from 'react';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { ResourceInUseBlocker } from '@/shared/api/extraTypes';
import { notify } from '@/shared/ui/notify';

/** Exclusão com confirmação; 409 resource_in_use mostra os bloqueios. */
export function useRemoveWithConfirm<T>(opts: { remove: (item: T) => Promise<unknown>; invalidate: QueryKey; successMessage: string; onDone?: () => void }) {
  const qc = useQueryClient();
  const [target, setTarget] = useState<T | null>(null);
  const m = useMutation({
    mutationFn: (item: T) => opts.remove(item),
    onSuccess: () => {
      notify.success(opts.successMessage);
      setTarget(null);
      void qc.invalidateQueries({ queryKey: opts.invalidate });
      opts.onDone?.();
    },
    onError: (e) => {
      if (isApiError(e) && e.code === 'resource_in_use') {
        const blockers = e.extra<ResourceInUseBlocker[]>('blockers') ?? [];
        notify.error(`${e.message}${blockers.length ? ` Em uso por: ${blockers.map((b) => b.label).join(', ')}.` : ''}`);
      } else notify.error(errorMessage(e));
      setTarget(null);
    },
  });
  return { target, ask: setTarget, cancel: () => setTarget(null), confirm: () => target !== null && m.mutate(target), loading: m.isPending };
}
