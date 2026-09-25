import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';
import { isApiError } from '@/shared/api/errors';

/**
 * Mapeia erros 422 do Laravel (`variants.0.sku`) para campos do RHF (`setError`).
 * `fieldMap` traduz chaves da API para nomes do formulário quando diferem.
 * `knownFields` (opcional) restringe o mapeamento; demais mensagens voltam para o resumo.
 * Retorna mensagens não mapeadas (exibidas no topo do formulário).
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  options: { fieldMap?: Record<string, string>; knownFields?: (key: string) => boolean } = {},
): string[] {
  if (!isApiError(error) || !error.fieldErrors) return [];
  const unmapped: string[] = [];
  let first = true;
  for (const [apiKey, messages] of Object.entries(error.fieldErrors)) {
    const key = options.fieldMap?.[apiKey] ?? apiKey;
    const message = messages[0] ?? 'Valor inválido.';
    if (options.knownFields && !options.knownFields(key)) {
      unmapped.push(message);
      continue;
    }
    setError(key as Path<T>, { type: 'server', message }, { shouldFocus: first });
    first = false;
  }
  return unmapped;
}
