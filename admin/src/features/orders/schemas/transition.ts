import { z } from 'zod';
import type { AdminTransition } from '@/shared/api/types';

/** Schema dinâmico conforme `required_fields` da transição (API §3.G.9). */
export function transitionSchema(t: AdminTransition) {
  const req = new Set(t.required_fields);
  const opt = (max: number, label: string) => z.string().trim().max(max, `${label}: no máximo ${max} caracteres.`);
  return z
    .object({
      note: opt(1000, 'Observação'),
      tracking_code: req.has('tracking_code') ? opt(100, 'Código').min(1, 'Informe o código de rastreio.') : opt(100, 'Código'),
      tracking_url: opt(500, 'URL').refine((v) => v === '' || /^https:\/\/\S+$/.test(v), 'Informe uma URL https válida.'),
      carrier_name: opt(100, 'Transportadora'),
      picked_up_by_name: req.has('picked_up_by_name') ? opt(150, 'Nome').min(3, 'Informe o nome de quem retirou (mín. 3 caracteres).') : opt(150, 'Nome'),
      picked_up_by_document: req.has('picked_up_by_document')
        ? z.string().trim().regex(/^[0-9A-Za-z.\-/ ]{5,20}$/, 'Informe o documento (CPF ou RG, 5 a 20 caracteres).')
        : z.string(),
      confirm_number: z.string(),
    })
    .strict();
}
export type TransitionForm = {
  note: string;
  tracking_code: string;
  tracking_url: string;
  carrier_name: string;
  picked_up_by_name: string;
  picked_up_by_document: string;
  confirm_number: string;
};

export const cancelSchema = (requiresRefund: boolean) =>
  z.object({
    reason_code: z.string().min(1, 'Selecione o motivo.'),
    reason_text: z.string().trim().max(500, 'No máximo 500 caracteres.'),
    confirm_refund: requiresRefund ? z.literal(true, 'Confirme o estorno para continuar.') : z.boolean(),
  });
export type CancelForm = { reason_code: string; reason_text: string; confirm_refund: boolean };

export const CANCEL_REASONS = [
  'Solicitação do cliente',
  'Falta de estoque',
  'Pagamento não identificado',
  'Suspeita de fraude',
  'Pedido duplicado',
  'Outro',
] as const;

/** Monta `reason` (3–500) a partir do motivo + detalhe. */
export function composeReason(v: CancelForm): string {
  const detail = v.reason_text.trim();
  if (v.reason_code === 'Outro') return detail;
  return detail ? `${v.reason_code} — ${detail}` : v.reason_code;
}
