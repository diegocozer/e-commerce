import { z } from 'zod';
import type { SaleUnit } from '@/shared/api/types';
import { toMilli } from '@/shared/formatters/quantity';

export const ENTRY_REASONS = ['Compra de fornecedor', 'Devolução', 'Outro'] as const;
export const ADJUST_REASONS = ['Inventário/contagem', 'Avaria/perda', 'Sobra de corte/perda', 'Correção de lançamento', 'Outro'] as const;

const INTEGER_STOCK: SaleUnit[] = ['UNIT', 'ROLL', 'BOX'];

/** Motivo composto "Compra de fornecedor — NF 4521" (3–255, API §3.G.8). */
export function composeReason(code: string, note: string): string {
  const n = note.trim();
  if (code === 'Outro') return n;
  return n ? `${code} — ${n}` : code;
}

function reasonRefine(v: { reason_code: string; note: string }, ctx: z.RefinementCtx) {
  if (!v.reason_code) ctx.addIssue({ code: 'custom', path: ['reason_code'], message: 'Selecione o motivo.' });
  else if (v.reason_code === 'Outro' && v.note.trim().length < 3) ctx.addIssue({ code: 'custom', path: ['note'], message: 'Descreva o motivo (mín. 3 caracteres).' });
  if (composeReason(v.reason_code, v.note).length > 255) ctx.addIssue({ code: 'custom', path: ['note'], message: 'O motivo deve ter no máximo 255 caracteres.' });
}

export const entrySchema = (unit: SaleUnit) =>
  z
    .object({ quantity: z.string(), reason_code: z.string(), note: z.string() })
    .superRefine((v, ctx) => {
      const m = toMilli(v.quantity);
      if (v.quantity === '' || m === null || m <= 0) ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Informe uma quantidade maior que zero.' });
      else if (m > 100_000_000) ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Máximo de 100.000.' });
      else if (INTEGER_STOCK.includes(unit) && m % 1000 !== 0) ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Use um número inteiro.' });
      reasonRefine(v, ctx);
    });
export type EntryForm = { quantity: string; reason_code: string; note: string };

export const adjustSchema = (current: { on_hand: number; reserved: number }, unitAbbr: string) =>
  z
    .object({ new_on_hand: z.string(), reason_code: z.string(), note: z.string() })
    .superRefine((v, ctx) => {
      const m = toMilli(v.new_on_hand);
      if (v.new_on_hand === '' || m === null || m < 0) ctx.addIssue({ code: 'custom', path: ['new_on_hand'], message: 'Informe o novo valor em mãos (≥ 0).' });
      else if (m < Math.round(current.reserved * 1000)) ctx.addIssue({ code: 'custom', path: ['new_on_hand'], message: `Não pode ser menor que o reservado (${String(current.reserved).replace('.', ',')} ${unitAbbr}).` });
      else if (m === Math.round(current.on_hand * 1000)) ctx.addIssue({ code: 'custom', path: ['new_on_hand'], message: 'O novo valor é igual ao atual.' });
      reasonRefine(v, ctx);
    });
export type AdjustForm = { new_on_hand: string; reason_code: string; note: string };
