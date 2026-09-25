import { isAxiosError } from 'axios';
import type { ErrorCode } from './types';

/** Código "sintético" para falhas sem resposta HTTP. */
export type ClientErrorCode = ErrorCode | 'network_error' | 'timeout' | 'validation' | 'unknown';

/** Mensagens pt-BR por código (API §1.6). O front trata por `code`, nunca por `message`. */
export const ERROR_MESSAGES: Record<ClientErrorCode, string> = {
  unauthenticated: 'Sua sessão expirou. Entre novamente para continuar.',
  admin_session_expired: 'Sua sessão expirou. Entre novamente para continuar.',
  forbidden: 'Você não tem permissão para esta ação.',
  account_disabled: 'Sua conta está desativada. Fale com nosso atendimento.',
  not_found: 'Não encontramos o que você procurava.',
  cart_not_found: 'Seu carrinho anterior expirou.',
  insufficient_stock: 'Estoque insuficiente para um ou mais itens.',
  price_changed: 'Os valores do pedido mudaram. Revise e confirme novamente.',
  shipping_quote_expired: 'A cotação de frete expirou. Escolha a entrega novamente.',
  shipping_option_unavailable: 'A opção de frete escolhida não está mais disponível.',
  shipping_quote_invalid: 'A cotação de frete não é mais válida. Escolha a entrega novamente.',
  shipping_quote_changed: 'O frete foi recalculado. Escolha a entrega novamente.',
  shipping_postal_code_changed: 'O CEP mudou. Calcule o frete novamente.',
  shipping_option_invalid: 'Opção de frete inválida. Escolha a entrega novamente.',
  shipping_price_changed: 'O valor do frete foi atualizado. Confira antes de confirmar.',
  coupon_invalid: 'O cupom deixou de valer para este pedido.',
  idempotency_conflict: 'Este pedido já foi enviado com outros dados. Revise e confirme novamente.',
  invalid_status_transition: 'Esta ação não está disponível para o pedido no status atual.',
  cart_empty: 'Seu carrinho está vazio.',
  cart_invalid: 'Alguns itens do carrinho precisam ser revistos.',
  too_many_pending_orders: 'Você já tem 3 pedidos aguardando pagamento. Pague ou cancele um deles para continuar.',
  resource_in_use: 'Este recurso está em uso.',
  stale_resource: 'Os dados mudaram enquanto você editava. Recarregue e tente de novo.',
  payload_too_large: 'O conteúdo enviado é grande demais.',
  csrf_token_mismatch: 'Sua sessão precisa ser renovada. Tente novamente.',
  too_many_requests: 'Muitas tentativas seguidas. Aguarde alguns segundos e tente novamente.',
  server_error: 'Algo deu errado do nosso lado. Tente novamente em instantes.',
  payment_gateway_unavailable: 'Não foi possível gerar o PIX agora. Seu pedido foi criado; tente novamente.',
  postal_code_lookup_unavailable: 'Não conseguimos buscar o CEP agora. Tente novamente em instantes.',
  service_unavailable: 'Loja em manutenção. Tente novamente em alguns minutos.',
  network_error: 'Não foi possível conectar. Verifique sua internet e tente novamente.',
  timeout: 'A resposta demorou demais. Tente novamente.',
  validation: 'Confira os campos destacados.',
  unknown: 'Não foi possível concluir. Tente novamente.',
};

export class ApiError extends Error {
  readonly status: number;
  readonly code: ClientErrorCode;
  /** Chaves em notação de ponto do Laravel (`company.cnpj`, `items.0.quantity`). */
  readonly fieldErrors: Record<string, string[]>;
  readonly details: Record<string, { suggestions?: number[] }>;
  readonly body: Record<string, unknown>;
  readonly requestId: string | null;
  readonly retryAfter: number | null;
  /** Mensagem do servidor (pt-BR) quando houver. */
  readonly serverMessage: string | null;

  constructor(init: {
    status: number;
    code: ClientErrorCode;
    message: string;
    fieldErrors?: Record<string, string[]>;
    details?: Record<string, { suggestions?: number[] }>;
    body?: Record<string, unknown>;
    requestId?: string | null;
    retryAfter?: number | null;
    serverMessage?: string | null;
  }) {
    super(init.message);
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.fieldErrors = init.fieldErrors ?? {};
    this.details = init.details ?? {};
    this.body = init.body ?? {};
    this.requestId = init.requestId ?? null;
    this.retryAfter = init.retryAfter ?? null;
    this.serverMessage = init.serverMessage ?? null;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  get isNetwork(): boolean {
    return this.code === 'network_error' || this.code === 'timeout';
  }

  /** Primeira mensagem de um campo (ex.: `quantity`). */
  fieldMessage(field: string): string | null {
    return this.fieldErrors[field]?.[0] ?? null;
  }

  /** Extra tipado do corpo (items, summary, shipping_quote, order…). */
  extra<T>(key: string): T | undefined {
    return this.body[key] as T | undefined;
  }
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

const STATUS_FALLBACK: Record<number, ClientErrorCode> = {
  401: 'unauthenticated',
  403: 'forbidden',
  404: 'not_found',
  413: 'payload_too_large',
  419: 'csrf_token_mismatch',
  429: 'too_many_requests',
  503: 'service_unavailable',
};

function retryAfterSeconds(value: unknown): number | null {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : null;
}

/** Normaliza qualquer erro (axios, rede, desconhecido) em ApiError. */
export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) return error;
  if (isAxiosError(error)) {
    const res = error.response;
    if (!res) {
      const timeout = error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT';
      return new ApiError({
        status: 0,
        code: timeout ? 'timeout' : 'network_error',
        message: ERROR_MESSAGES[timeout ? 'timeout' : 'network_error'],
      });
    }
    const headers = res.headers as Record<string, unknown>;
    const requestId = typeof headers['x-request-id'] === 'string' ? (headers['x-request-id'] as string) : null;
    const retryAfter = retryAfterSeconds(headers['retry-after']);
    const body = isRecord(res.data) ? res.data : {};
    const serverMessage = typeof body.message === 'string' ? body.message : null;
    if (res.status === 422) {
      const fieldErrors = isRecord(body.errors) ? (body.errors as Record<string, string[]>) : {};
      const details = isRecord(body.details) ? (body.details as Record<string, { suggestions?: number[] }>) : {};
      const code: ClientErrorCode = body.code === 'cart_invalid' ? 'cart_invalid' : 'validation';
      return new ApiError({
        status: 422,
        code,
        message: serverMessage ?? ERROR_MESSAGES.validation,
        fieldErrors,
        details,
        body,
        requestId,
        serverMessage,
      });
    }
    const rawCode = typeof body.code === 'string' ? (body.code as ClientErrorCode) : undefined;
    const code: ClientErrorCode =
      rawCode && rawCode in ERROR_MESSAGES
        ? rawCode
        : (STATUS_FALLBACK[res.status] ?? (res.status >= 500 ? 'server_error' : 'unknown'));
    // Mensagens do servidor já vêm em pt-BR; para 5xx usamos texto genérico (sem detalhes técnicos).
    const message = res.status >= 500 && code === 'server_error' ? ERROR_MESSAGES.server_error : (serverMessage ?? ERROR_MESSAGES[code]);
    return new ApiError({ status: res.status, code, message, body, requestId, retryAfter, serverMessage });
  }
  if (error instanceof Error) {
    return new ApiError({ status: 0, code: 'unknown', message: ERROR_MESSAGES.unknown, serverMessage: error.message });
  }
  return new ApiError({ status: 0, code: 'unknown', message: ERROR_MESSAGES.unknown });
}

/** Texto amigável para snackbars/Alert, com código para suporte em 5xx. */
export function describeError(error: unknown): string {
  const e = toApiError(error);
  if (e.code === 'too_many_requests' && e.retryAfter) {
    return `Muitas tentativas seguidas. Aguarde ${e.retryAfter} s e tente novamente.`;
  }
  if (e.status >= 500 && e.requestId) return `${e.message} Código para suporte: ${e.requestId}`;
  return e.message;
}

export function isShippingCode(code: ClientErrorCode): boolean {
  return code.startsWith('shipping_');
}
