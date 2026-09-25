import type { ErrorCode } from './types';

export interface ApiErrorInit {
  status: number;
  message: string;
  code?: ErrorCode | string;
  fieldErrors?: Record<string, string[]>;
  body?: unknown;
  requestId?: string | null;
  retryAfter?: number | null;
}

/** Erro normalizado da API (ARCHITECTURE §8.5). Trate por `code`, nunca por `message`. */
export class ApiError extends Error {
  readonly status: number;
  readonly code?: ErrorCode | string;
  readonly fieldErrors?: Record<string, string[]>;
  readonly body: unknown;
  readonly requestId: string | null;
  readonly retryAfter: number | null;

  constructor(init: ApiErrorInit) {
    super(init.message);
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.fieldErrors = init.fieldErrors;
    this.body = init.body;
    this.requestId = init.requestId ?? null;
    this.retryAfter = init.retryAfter ?? null;
  }

  get isValidation(): boolean {
    return this.status === 422 && !!this.fieldErrors;
  }

  /** Campo extra do corpo (ex.: `allowed_transitions`, `blockers`). */
  extra<T>(key: string): T | undefined {
    if (this.body && typeof this.body === 'object' && key in this.body) {
      return (this.body as Record<string, unknown>)[key] as T;
    }
    return undefined;
  }
}

export function isApiError(e: unknown): e is ApiError {
  return e instanceof ApiError;
}

/** Mensagem amigável pt-BR para qualquer erro (UX §6.2/§6.6). */
export function errorMessage(e: unknown): string {
  if (isApiError(e)) {
    if (e.status === 0) return 'Não foi possível conectar. Verifique sua conexão e tente novamente.';
    if (e.status === 429) {
      return e.retryAfter
        ? `Muitas tentativas. Aguarde ${e.retryAfter} s e tente novamente.`
        : 'Muitas tentativas. Aguarde um instante e tente novamente.';
    }
    if (e.status >= 500) {
      const base = e.message && e.code !== 'server_error' ? e.message : 'Ocorreu um erro inesperado. Tente novamente.';
      return e.requestId ? `${base} Código para suporte: ${e.requestId}` : base;
    }
    return e.message || 'Não foi possível concluir a ação.';
  }
  if (e instanceof Error) return e.message;
  return 'Não foi possível concluir a ação.';
}
