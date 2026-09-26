import { useState } from 'react';
import type { FieldValues, UseFormSetError } from 'react-hook-form';
import { errorMessage, isApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/ui/form/serverErrors';

/**
 * Envolve o submit: 422 → campos (setError) + mensagens não mapeadas no topo;
 * outros erros → mensagem no topo. Retorna [erroTopo, run].
 */
export function useFormSubmit<T extends FieldValues>(setError: UseFormSetError<T>, fieldMap?: Record<string, string>) {
  const [error, setErr] = useState<string | null>(null);
  const run = async (fn: () => Promise<unknown>): Promise<boolean> => {
    setErr(null);
    try {
      await fn();
      return true;
    } catch (e) {
      if (isApiError(e) && e.fieldErrors) {
        const rest = applyServerErrors(e, setError, { fieldMap });
        if (rest.length) setErr(rest.join(' '));
      } else setErr(errorMessage(e));
      return false;
    }
  };
  return { error, setError: setErr, run };
}
