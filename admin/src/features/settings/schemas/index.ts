import type { SettingKey } from '@/shared/api/types';
import { onlyDigits } from '@/shared/formatters/document';

export const SETTING_LABEL: Record<SettingKey, string> = {
  'store.name': 'Nome da loja', 'store.legal_name': 'Razão social', 'store.document': 'CNPJ', 'store.address': 'Endereço da loja', 'store.phone': 'Telefone',
  'store.whatsapp': 'WhatsApp', 'store.email': 'E-mail de atendimento', 'store.opening_hours': 'Horário de atendimento', 'store.social_links': 'Redes sociais',
  'orders.number_prefix': 'Prefixo do número do pedido', 'checkout.pix_expiry_minutes': 'Expiração do PIX (minutos)', 'checkout.min_order_cents': 'Pedido mínimo',
  'cart.guest_ttl_days': 'Validade do carrinho de visitante (dias)', 'shipping.quote_ttl_minutes': 'Validade da cotação de frete (minutos)',
  'shipping.origin_postal_code': 'CEP de origem', 'inventory.default_low_stock_threshold': 'Estoque baixo — limite padrão',
  'inventory.show_low_stock_quantity': 'Exibir "Últimas unidades" com quantidade na loja', 'legal.terms_version': 'Versão dos termos de uso',
  'storefront.free_shipping_banner': 'Banner de frete grátis', 'notifications.whatsapp_enabled': 'Notificações por WhatsApp',
  'notifications.admin_alert_emails': 'E-mails de alerta da equipe', 'content.about': 'Sobre nós', 'content.terms': 'Termos de uso',
  'content.privacy': 'Política de privacidade', 'content.returns': 'Trocas e devoluções',
};

export const GROUP_LABEL: Record<string, string> = {
  store: 'Loja', checkout: 'Pedidos e pagamentos', shipping: 'Frete', inventory: 'Estoque', legal: 'Jurídico', storefront: 'Vitrine', notifications: 'Notificações', content: 'Institucional',
};

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const intIn = (v: unknown, min: number, max: number) => typeof v === 'number' && Number.isInteger(v) && v >= min && v <= max;

/** Validação da whitelist tipada (API §3.G.13). Retorna mensagem ou null. */
export function validateSetting(key: SettingKey, v: unknown): string | null {
  const s = typeof v === 'string' ? v : '';
  switch (key) {
    case 'store.name':
      return s.trim().length >= 2 && s.length <= 120 ? null : 'Use 2 a 120 caracteres.';
    case 'store.legal_name':
      return s.length <= 200 ? null : 'No máximo 200 caracteres.';
    case 'store.document':
      return /^[0-9A-Z]{12}\d{2}$/.test(s.toUpperCase().replace(/[^0-9A-Z]/g, '')) ? null : 'CNPJ inválido.';
    case 'store.phone':
      return /^\d{10,11}$/.test(onlyDigits(s)) ? null : 'Telefone com DDD (10 ou 11 dígitos).';
    case 'store.whatsapp':
      return v === null || s === '' || /^\d{10,13}$/.test(onlyDigits(s)) ? null : 'WhatsApp com 10 a 13 dígitos.';
    case 'store.email':
      return EMAIL.test(s) ? null : 'E-mail inválido.';
    case 'store.opening_hours':
      return s.length <= 200 ? null : 'No máximo 200 caracteres.';
    case 'orders.number_prefix':
      return /^[A-Z]{1,5}-$/.test(s) ? null : 'Use 1 a 5 letras maiúsculas seguidas de hífen (ex.: CV-).';
    case 'checkout.pix_expiry_minutes':
      return intIn(v, 5, 1440) ? null : 'Entre 5 e 1440 minutos.';
    case 'checkout.min_order_cents':
      return intIn(v, 0, Number.MAX_SAFE_INTEGER) ? null : 'Valor inválido.';
    case 'cart.guest_ttl_days':
      return intIn(v, 1, 90) ? null : 'Entre 1 e 90 dias.';
    case 'shipping.quote_ttl_minutes':
      return intIn(v, 5, 120) ? null : 'Entre 5 e 120 minutos.';
    case 'shipping.origin_postal_code':
      return onlyDigits(s).length === 8 ? null : 'CEP inválido.';
    case 'legal.terms_version':
      return s.trim().length >= 1 && s.length <= 20 ? null : 'Até 20 caracteres.';
    case 'notifications.admin_alert_emails':
      return Array.isArray(v) && v.length <= 10 && v.every((e) => typeof e === 'string' && EMAIL.test(e)) ? null : 'Até 10 e-mails válidos.';
    case 'storefront.free_shipping_banner': {
      const b = v as { threshold_cents?: unknown; text?: unknown } | null;
      if (!b) return null;
      if (!intIn(b.threshold_cents, 0, Number.MAX_SAFE_INTEGER)) return 'Informe o valor mínimo.';
      return typeof b.text === 'string' && b.text.length <= 160 ? null : 'Texto até 160 caracteres.';
    }
    case 'store.social_links': {
      const o = (v ?? {}) as Record<string, string | null>;
      return Object.values(o).every((u) => !u || /^https:\/\/\S+$/.test(u)) ? null : 'Use URLs https.';
    }
    default:
      if (key.startsWith('content.')) return s.length <= 50000 ? null : 'No máximo 50.000 caracteres.';
      return null;
  }
}
