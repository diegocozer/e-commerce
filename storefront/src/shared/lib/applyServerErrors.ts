import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { toApiError } from '../api/errors';

/**
 * 422 → setError por campo (chaves com ponto do Laravel = paths do RHF).
 * Retorna mensagens de campos desconhecidos (para Alert no topo) ou a mensagem geral.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  knownFields: readonly string[],
  aliases: Record<string, string> = {},
): string[] {
  const e = toApiError(error);
  if (!e.isValidation) return [e.message];
  const unknown: string[] = [];
  let first = true;
  for (const [key, messages] of Object.entries(e.fieldErrors)) {
    const field = aliases[key] ?? key;
    const message = messages[0];
    if (knownFields.includes(field)) {
      setError(field as Path<T>, { type: 'server', message }, { shouldFocus: first });
      first = false;
    } else if (message) unknown.push(message);
  }
  if (Object.keys(e.fieldErrors).length === 0) unknown.push(e.message);
  return unknown;
}
