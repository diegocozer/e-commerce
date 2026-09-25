/**
 * Tipos do contrato HTTP — copiados literalmente de docs/API.md (§1.4, §1.6, §2).
 * NÃO editar à mão: API.md é canônico (ADR-028). Tipos extras do painel ficam em ./extraTypes.ts.
 */
/* eslint-disable */
export interface Paginated<T> {
  data: T[];
  links: { first: string; last: string; prev: string | null; next: string | null };
  meta: {
    current_page: number; from: number | null; last_page: number; path: string;
    per_page: number; to: number | null; total: number;
    links: { url: string | null; label: string; active: boolean }[];
  };
}

export interface ValidationErrorBody {
  message: string;
  errors: Record<string, string[]>;
  details?: Record<string, { suggestions?: number[] }>;
  code?: 'cart_invalid';
  items?: CartItemIssue[];
}
export interface ApiErrorBody {
  message: string;
  code: ErrorCode;
  [extra: string]: unknown;
}
export type ErrorCode =
  | 'unauthenticated' | 'admin_session_expired' | 'forbidden' | 'account_disabled'
  | 'not_found' | 'cart_not_found' | 'insufficient_stock' | 'price_changed'
  | 'shipping_quote_expired' | 'shipping_option_unavailable' | 'shipping_quote_invalid'
  | 'shipping_quote_changed' | 'shipping_postal_code_changed' | 'shipping_option_invalid'
  | 'shipping_price_changed' | 'coupon_invalid' | 'idempotency_conflict'
  | 'invalid_status_transition' | 'cart_empty' | 'cart_invalid' | 'too_many_pending_orders'
  | 'resource_in_use' | 'stale_resource' | 'payload_too_large' | 'csrf_token_mismatch'
  | 'too_many_requests' | 'server_error' | 'payment_gateway_unavailable'
  | 'postal_code_lookup_unavailable' | 'service_unavailable';

export type Cents = number;          // inteiro, centavos BRL
export type BasisPoints = number;    // inteiro, 1000 = 10%
export type Decimal3 = number;       // número com até 3 casas decimais
export type Grams = number;          // inteiro
export type ISODateTime = string;    // "2026-09-24T13:00:00Z" (UTC)
export type ISODate = string;        // "2026-09-24"
export type UUID = string;

export type SaleUnit = 'UNIT' | 'LINEAR_METER' | 'SQUARE_METER' | 'ROLL' | 'KG' | 'BOX';
export type PriceSource =
  | 'base' | 'tier' | 'price_list' | 'variant_promo' | 'promotion' | 'customer_price';
export type AvailabilityStatus = 'in_stock' | 'low_stock' | 'out_of_stock';
export type CustomerType = 'individual' | 'company';
export type OrderStatus =
  | 'pending_payment' | 'paid' | 'processing' | 'shipped' | 'delivered'
  | 'ready_for_pickup' | 'picked_up' | 'cancelled';
export type OrderPaymentStatus = 'pending' | 'approved' | 'failed' | 'refunded' | 'expired';
export type PaymentRecordStatus =
  | 'pending' | 'approved' | 'failed' | 'expired' | 'cancelled' | 'refunded' | 'partially_refunded';
export type PaymentMethod = 'pix' | 'credit_card' | 'boleto' | 'invoice'; // MVP aceita só 'pix'
export type PaymentProvider = 'sandbox' | 'mercadopago';
export type CancelReasonCode = 'payment_expired' | 'customer' | 'admin' | 'payment_failed';
export type ShippingMethodType = 'pickup' | 'own_delivery' | 'table_rate' | 'carrier';
export type ShippingPriceType = 'fixed' | 'per_kg' | 'fixed_plus_per_kg' | 'percentage_of_subtotal' | 'free';
export type WeightBasis = 'real' | 'chargeable';
export type FreeShippingReason = 'rule' | 'coupon';
export type InventoryMovementType = 'in' | 'out' | 'reserve' | 'release' | 'return' | 'adjust';
export type CouponType = 'percent' | 'fixed' | 'free_shipping';
export type PromotionDiscountType = 'percent' | 'fixed';
export type PromotionScope = 'all' | 'targeted';
export type PriceListKind = 'retail' | 'wholesale' | 'reseller' | 'custom';
export type ActorType = 'admin' | 'customer' | 'system';
export type UF =
  | 'AC' | 'AL' | 'AP' | 'AM' | 'BA' | 'CE' | 'DF' | 'ES' | 'GO' | 'MA' | 'MT' | 'MS' | 'MG' | 'PA'
  | 'PB' | 'PR' | 'PE' | 'PI' | 'RJ' | 'RN' | 'RS' | 'RO' | 'RR' | 'SC' | 'SP' | 'SE' | 'TO';

export interface CategoryRef { id: number; name: string; slug: string; url_path: string } // url_path = "/vinis"
export interface BrandRef { id: number; name: string; slug: string }
export interface Breadcrumb { name: string; url_path: string | null } // último item: url_path null

export interface ImageUrls { w300: string; w800: string; w1600: string } // WebP
export interface ProductImage {
  id: number;
  urls: ImageUrls | null;       // null enquanto o job ProcessProductImage não terminou
  alt: string;                  // nunca vazio (fallback = nome do produto)
  width: number | null;         // px da maior versão; null = processando
  height: number | null;
  position: number;             // 0 = capa
  variant_id: number | null;
}

export interface Seo {
  title: string;
  description: string;
  canonical_path: string;       // "/vinis/vinil-adesivo-branco-122m"
  canonical_url: string;        // absoluto (APP_URL + canonical_path)
  robots: 'index,follow' | 'noindex,follow' | 'noindex,nofollow';
  og_image_url: string | null;
  json_ld: Record<string, unknown>[]; // Product e/ou BreadcrumbList, prontos para <script type="application/ld+json">
}

export interface SaleUnitRules {
  sale_unit: SaleUnit;
  input: 'integer' | 'decimal' | 'dimensions'; // UNIT/ROLL/BOX: integer; LINEAR_METER/KG: decimal; SQUARE_METER: dimensions
  min_quantity: Decimal3;       // SQUARE_METER: peças (ADR-019)
  max_quantity: Decimal3 | null;
  quantity_step: Decimal3;      // SQUARE_METER: peças (sempre inteiro)
  // SQUARE_METER (e LINEAR_METER informativo):
  fixed_width_m: Decimal3 | null;   // efetivo = variante ?? produto
  min_width_m: Decimal3 | null;
  max_width_m: Decimal3 | null;
  min_height_m: Decimal3 | null;
  max_height_m: Decimal3 | null;
  min_billable_area_m2: Decimal3 | null; // por peça (ADR-019)
  dimension_decimals: 2;        // precisão aceita na entrada de largura/altura (1 cm)
  max_pieces: 1000;
}

export interface PriceTierDisplay {
  min_quantity: Decimal3;       // inclusivo, na unidade faturada (m² para SQUARE_METER)
  max_quantity: Decimal3 | null;// maior quantidade válida antes da próxima faixa; null = sem limite
  unit_price_cents: Cents;      // preço RESOLVIDO para o cliente atual nessa faixa
  price_source: PriceSource;
}

export interface VariantPrice {
  unit_price_cents: Cents;      // resolvido p/ quantidade mínima faturável e cliente atual
  base_unit_price_cents: Cents; // product_variants.price_cents
  compare_at_cents: Cents | null; // = base quando unit < base ("de R$ X por R$ Y"); senão null
  price_source: PriceSource;
  price_source_label: string | null;
  promotion: { name: string; ends_at: ISODateTime | null } | null;
  tiers: PriceTierDisplay[];    // tabela "preço por quantidade" do contexto atual; [] se preço único
}

export interface Availability {
  status: AvailabilityStatus;
  // Só preenchido quando status = 'low_stock' ("Restam 3,5 m"); unidade de estoque. Senão null.
  available_quantity: Decimal3 | null;
}

export interface ProductCard {
  id: number;
  slug: string;
  name: string;
  url_path: string;             // "/{primary_category_slug}/{slug}"
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  image: ProductImage | null;   // capa
  variants_count: number;       // ativas
  default_variant: { id: number; sku: string; name: string }; // SKU exibido quando variants_count = 1
  key_attribute: string | null; // derivado: "Largura 1,22 m" | "Rolo 50 m" | "Caixa c/ 1000 un" | null
  price: {
    unit_price_cents: Cents;    // da variante padrão, na quantidade mínima, para o cliente atual
    compare_at_cents: Cents | null;
    price_source: PriceSource;
    price_source_label: string | null;
    from_price_cents: Cents | null; // menor preço possível (todas as variantes/faixas) quando < unit_price_cents
  };
  availability: { status: AvailabilityStatus }; // agregado: melhor status entre variantes ativas
  pickup_only: boolean;
  is_featured: boolean;
  quick_add: boolean;           // true se UNIT/ROLL/BOX, 1 variante, disponível e min_quantity = 1
}

export interface ProductVariant {
  id: number;
  sku: string;
  gtin: string | null;
  name: string;                 // "Brilho", "Padrão"
  attributes: Record<string, string>; // exibição: {"cor": "Branco", "acabamento": "Brilho"}
  position: number;
  is_default: boolean;
  image_ids: number[];          // imagens vinculadas à variante
  rules: SaleUnitRules;         // efetivas (produto + override da variante)
  price: VariantPrice;
  availability: Availability;
  weight_grams: Grams;          // por unidade de venda (0 = não informado)
  roll_length_m: Decimal3 | null;
  units_per_box: number | null;
}

export interface ProductDetail {
  id: number;
  slug: string;
  name: string;
  url_path: string;
  short_description: string | null; // texto puro
  description_html: string | null;  // HTML sanitizado (ADR-024) — renderizar com <SafeHtml>
  specifications: { label: string; value: string }[]; // ficha técnica (RN-CAT-015)
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  categories: CategoryRef[];
  breadcrumbs: Breadcrumb[];    // Início › … › categoria principal (e ancestrais) › produto
  images: ProductImage[];
  attribute_axes: { key: string; label: string; values: string[] }[]; // eixos do seletor de variantes
  variants: ProductVariant[];   // só ativas, ordenadas por position
  default_variant_id: number;
  pickup_only: boolean;
  is_featured: boolean;
  seo: Seo;
}

export interface CategoryNode {
  id: number;
  name: string;
  slug: string;
  url_path: string;
  image_url: string | null;
  position: number;
  children: CategoryNode[];     // até 3 níveis
}
export interface CategoryDetail {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  url_path: string;
  description_html: string | null;
  image_url: string | null;
  breadcrumbs: Breadcrumb[];
  children: CategoryNode[];
  seo: Seo;
}
export interface Brand { id: number; name: string; slug: string; logo_url: string | null }

export interface ProductFacets {
  categories: { slug: string; name: string; count: number }[];
  brands: { slug: string; name: string; count: number }[];
  sale_units: { value: SaleUnit; label: string; count: number }[];
  price_range_cents: { min: Cents; max: Cents } | null; // preço base, conjunto filtrado
}

export interface LineConfiguration {
  quantity: Decimal3 | null;    // não-SQUARE_METER
  width_m: Decimal3 | null;     // SQUARE_METER (com largura fixa = fixed_width_m)
  height_m: Decimal3 | null;
  pieces: number | null;
}

export interface PricePreview {
  variant_id: number;
  sale_unit: SaleUnit;
  configuration: LineConfiguration;
  configuration_label: string;  // "5 m" | "1,20 m × 2,50 m × 1 peça" | "2 rolos"
  billable_quantity: Decimal3;  // faturada (m² após área mínima; demais = quantity)
  stock_quantity: Decimal3;     // baixa de estoque (m² real; demais = quantity)
  piece_area_m2: Decimal3 | null;   // SQUARE_METER: área calculada de 1 peça
  area_m2: Decimal3 | null;         // SQUARE_METER: área calculada total (peças)
  min_area_applied: boolean;
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  compare_at_cents: Cents | null;
  price_source: PriceSource;
  price_source_label: string | null;
  line_total_cents: Cents;      // round_half_up(unit × billable_milli / 1000)
  weight_grams: Grams;          // ceil(weight_grams × billable_milli / 1000); KG = billable em g
  applied_tier: PriceTierDisplay | null;
  next_tier: (PriceTierDisplay & { missing_quantity: Decimal3 }) | null;
  stock: { sufficient: boolean; available_quantity: Decimal3 | null }; // available só quando insuficiente
}

export type CartItemStatus = 'ok' | 'unavailable' | 'insufficient_stock' | 'invalid_quantity';

export type CartItemWarning =
  | { code: 'price_changed'; previous_unit_price_cents: Cents; current_unit_price_cents: Cents }
  | { code: 'unavailable'; message: string }                       // produto/variante inativa ou excluída
  | { code: 'insufficient_stock'; requested_quantity: Decimal3; available_quantity: Decimal3; message: string }
  | { code: 'invalid_quantity'; message: string; suggestions: number[] } // regra mudou (RN-CAR-031)
  | { code: 'min_area_applied'; area_m2: Decimal3; billable_area_m2: Decimal3 }; // informativo

export interface CartItem {
  id: number;
  variant_id: number;
  product: { id: number; slug: string; name: string; url_path: string; image: ProductImage | null };
  variant: { id: number; sku: string; name: string; attributes: Record<string, string> };
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3 | null;   // null se invalid_quantity/unavailable sem dados
  stock_quantity: Decimal3 | null;
  piece_area_m2: Decimal3 | null;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;
  unit_price_cents: Cents | null;       // null se unavailable/invalid_quantity
  base_unit_price_cents: Cents | null;
  compare_at_cents: Cents | null;
  price_source: PriceSource | null;
  price_source_label: string | null;
  line_total_cents: Cents | null;
  weight_grams: Grams;
  status: CartItemStatus;               // != 'ok' (exceto warnings informativos) bloqueia checkout
  warnings: CartItemWarning[];
  rules: SaleUnitRules | null;          // para edição inline (null se variante indisponível)
  availability: Availability;
}

export interface CartCoupon {
  code: string;
  description: string | null;
  type: CouponType;
  valid: boolean;
  reason_code: null | 'login_required' | 'expired' | 'inactive' | 'min_order_not_met'
             | 'usage_limit_reached' | 'customer_limit_reached' | 'not_found';
  message: string | null;               // pt-BR, quando !valid
  discount_cents: Cents;                // 0 quando !valid ou free_shipping
  free_shipping: boolean;
}

export interface Cart {
  token: UUID | null;                   // visitante: token do carrinho; cliente: null (carrinho pela sessão)
  owner: 'guest' | 'customer';
  items: CartItem[];                    // ordem de inserção
  items_count: number;                  // nº de linhas
  coupon: CartCoupon | null;
  postal_code: string | null;           // último CEP cotado
  totals: {
    subtotal_cents: Cents;              // Σ line_total_cents das linhas precificáveis (status ok | insufficient_stock)
    discount_cents: Cents;              // cupom sobre itens
    shipping_cents: Cents | null;       // só com shipping_quote_id+shipping_option_id válidos na query
    shipping_discount_cents: Cents | null;
    total_cents: Cents;                 // subtotal − discount + (shipping − shipping_discount ?? 0)
  };
  shipping_selection: {
    quote_id: UUID; option_id: string; option: ShippingOption; valid: boolean;
    issue_code: string | null;          // ex.: 'shipping_quote_expired' (então valid=false e shipping_cents=null)
  } | null;
  total_weight_grams: Grams;
  free_shipping_progress: { threshold_cents: Cents; remaining_cents: Cents; text: string } | null;
  price_list: { code: string; name: string } | null; // tabela efetiva do cliente (null = varejo/visitante)
  can_checkout: boolean;                // itens ≥ 1, todos status 'ok', cupom válido ou ausente
  blocking_reasons: ('cart_empty' | 'item_unavailable' | 'item_insufficient_stock'
                     | 'item_invalid_quantity' | 'coupon_invalid')[];
  has_price_changes: boolean;           // algum warning price_changed
  updated_at: ISODateTime | null;
}

export interface CartMergeReport {
  merged: boolean;                      // havia carrinho de visitante com itens
  lines_added: number;                  // linhas novas no carrinho do cliente
  lines_combined: number;               // linhas somadas a existentes
  adjustments: {
    variant_id: number; sku: string; product_name: string;
    previous_quantity: Decimal3;        // na unidade da linha (peças em SQUARE_METER)
    quantity: Decimal3;
    reason: 'max_quantity' | 'insufficient_stock';
  }[];
  dropped: {
    variant_id: number; sku: string; product_name: string;
    reason: 'unavailable' | 'line_limit' | 'invalid_quantity';
  }[];
  coupon: { code: string; kept: boolean; reason_code: CartCoupon['reason_code'] } | null;
}

export interface CartItemIssue {        // usado em 409/422 do checkout e da recompra
  cart_item_id: number | null;
  variant_id: number;
  sku: string;
  product_name: string;
  reason: 'unavailable' | 'invalid_quantity' | 'insufficient_stock';
  message: string;
}
export interface StockIssue {
  cart_item_id: number | null;
  variant_id: number;
  sku: string;
  product_name: string;
  requested_quantity: Decimal3;        // unidade de estoque (m² em SQUARE_METER; soma da variante)
  available_quantity: Decimal3;
}

export interface PickupAddress {
  street: string; number: string; complement: string | null; district: string;
  city: string; state: UF; postal_code: string;
  opening_hours: string | null; instructions: string | null;
}

export interface ShippingOption {       // SHIPPING.md §3 (campos internos rule_id/method_position não expostos)
  option_id: string;                    // "1:pickup" | "2:14" | "5:SEDEX" — enviar no checkout
  method_code: string;
  method_type: ShippingMethodType;
  name: string;
  description: string | null;
  price_cents: Cents;                   // valor cobrado
  original_price_cents: Cents;          // antes do frete grátis
  is_free: boolean;
  free_reason: FreeShippingReason | null;
  delivery_days_min: number;            // dias úteis
  delivery_days_max: number;
  delivery_label: string;               // "1 dia útil" | "2 a 4 dias úteis" | "Disponível em 1 dia útil após o pagamento"
  carrier: { code: string; name: string; service_code: string | null; service_name: string | null } | null;
  pickup_address: PickupAddress | null;
}

export interface ShippingQuote {
  quote_id: UUID | null;                // null em estimativa de produto (não persistida)
  expires_at: ISODateTime | null;
  destination: { postal_code: string; city: string | null; state: UF | null };
  options: ShippingOption[];            // ordenadas: preço ↑, prazo máx ↑, prazo mín ↑, posição do método
  notice: 'pickup_only_items' | null;
  message: string | null;               // "Não há opções de entrega para este CEP." quando options = []
  total_weight_grams: Grams;
}

export interface PostalCodeInfo {
  postal_code: string;                  // 8 dígitos
  street: string | null;                // null em CEP geral de cidade
  district: string | null;
  city: string;
  state: UF;
  city_ibge_code: string;               // 7 dígitos
  source: 'viacep' | 'cache';
}

export interface Company {
  legal_name: string;
  trade_name: string | null;
  cnpj: string;                         // completo (dado do próprio cliente)
  state_registration: string | null;    // null quando isento
  state_registration_exempt: boolean;
}

export interface Customer {
  uuid: UUID;
  type: CustomerType;
  name: string;                         // PF: nome; PJ: responsável
  email: string;
  email_verified: boolean;
  phone: string | null;
  cpf: string | null;                   // PF: sempre; PJ: opcional (responsável)
  marketing_opt_in: boolean;
  company: Company | null;              // só PJ
  price_list: { code: string; name: string } | null; // efetiva (cliente → empresa); null = varejo
  terms: { accepted_version: string; current_version: string; needs_acceptance: boolean };
  profile_complete: boolean;            // dados de faturamento completos para o checkout
  missing_fields: string[];             // ex.: ["phone"]
  created_at: ISODateTime;
}

export interface Address {
  uuid: UUID;
  label: string | null;                 // "Casa", "Loja"
  recipient_name: string;
  phone: string | null;
  postal_code: string;
  street: string;
  number: string;                       // aceita "S/N"
  complement: string | null;
  district: string;
  city: string;                         // derivado do CEP (não editável)
  state: UF;                            // derivado do CEP
  city_ibge_code: string | null;        // derivado do CEP
  reference: string | null;
  is_default: boolean;
  formatted: string;                    // "Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000"
  created_at: ISODateTime;
}

export interface PixData {
  qr_code_base64: string | null;        // PNG base64 SEM prefixo "data:"; usar src={`data:image/png;base64,${v}`}
  copy_paste: string;                   // payload EMV "copia e cola"
  expires_at: ISODateTime;
}

export interface OrderPayment {
  uuid: UUID;
  method: PaymentMethod;
  status: PaymentRecordStatus;
  amount_cents: Cents;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  pix: PixData | null;                  // null se o PIX ainda não foi gerado (gateway falhou) ou não é PIX
}

export interface OrderItem {
  product_id: number;
  product_name: string;                 // snapshot
  variant_name: string;
  sku: string;
  product_url_path: string | null;      // null se produto inativo/excluído hoje
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3;
  stock_quantity: Decimal3;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;            // billable_quantity > stock_quantity
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  price_source: PriceSource;
  subtotal_cents: Cents;
  discount_cents: Cents;                // rateio do cupom
  total_cents: Cents;
  weight_grams: Grams;
}

export interface OrderTimelineEntry {
  status: OrderStatus;
  status_label: string;                 // "Pedido realizado", "Pagamento aprovado", "Em separação"...
  occurred_at: ISODateTime;
  note: string | null;                  // somente notas públicas: rastreio, motivo de cancelamento
}

export interface OrderAllowedActions {
  can_pay: boolean;                     // pending_payment, não expirado, PIX disponível
  can_retry_payment: boolean;           // pending_payment, não expirado, sem PIX ativo utilizável
  can_cancel: boolean;                  // pending_payment
  can_request_cancellation: boolean;    // paid|processing e sem solicitação aberta
  can_reorder: boolean;                 // sempre true (RN-PED-045)
}

export interface OrderSummary {
  uuid: UUID;
  number: string;                       // "CV-000123"
  status: OrderStatus;
  status_label: string;
  payment_status: OrderPaymentStatus;
  payment_method: PaymentMethod;
  placed_at: ISODateTime;
  expires_at: ISODateTime | null;       // prazo de pagamento (pending_payment)
  items_count: number;
  total_cents: Cents;
  shipping_method_name: string;
  shipping_method_type: ShippingMethodType;
  tracking_code: string | null;
  allowed_actions: OrderAllowedActions;
}

export interface OrderShippingSnapshot {
  method_name: string;
  method_type: ShippingMethodType;
  carrier_code: string | null;
  service_code: string | null;
  delivery_days_min: number | null;
  delivery_days_max: number | null;
  delivery_label: string | null;
  estimated_delivery_date: ISODate | null; // gravada no pagamento
  tracking_code: string | null;
  tracking_url: string | null;
  address: {                            // null quando retirada
    recipient_name: string; phone: string | null; postal_code: string; street: string;
    number: string; complement: string | null; district: string; city: string; state: UF;
    reference: string | null; formatted: string;
  } | null;
  pickup_address: PickupAddress | null; // quando retirada (endereço atual do método)
  picked_up_at: ISODateTime | null;
  picked_up_by_name: string | null;
}

export interface OrderDetail extends OrderSummary {
  paid_at: ISODateTime | null;
  cancelled_at: ISODateTime | null;
  cancel_reason_code: CancelReasonCode | null;
  cancel_reason_label: string | null;   // texto público ("Pagamento não realizado no prazo")
  cancellation_request: { requested_at: ISODateTime; reason: string | null } | null;
  items: OrderItem[];
  totals: {
    subtotal_cents: Cents; discount_cents: Cents; shipping_cents: Cents;
    shipping_discount_cents: Cents; total_cents: Cents;
  };
  coupon_code: string | null;
  total_weight_grams: Grams;
  billing: {                            // snapshot do comprador
    customer_type: CustomerType; name: string; email: string; document: string;
    phone: string | null; company_name: string | null; state_registration: string | null;
  };
  shipping: OrderShippingSnapshot;
  payment: OrderPayment | null;         // pagamento mais recente
  timeline: OrderTimelineEntry[];       // ordem cronológica
  notes: string | null;                 // observação do cliente
}

export interface OrderStatusPoll {
  uuid: UUID;
  number: string;
  status: OrderStatus;
  payment_status: OrderPaymentStatus;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  payment: { uuid: UUID; status: PaymentRecordStatus; expires_at: ISODateTime | null; has_pix: boolean } | null;
  updated_at: ISODateTime;
}

export interface CheckoutBlocking {
  code: 'cart_empty' | 'cart_invalid' | 'insufficient_stock' | 'coupon_invalid'
      | 'shipping_required' | 'profile_incomplete' | 'too_many_pending_orders'
      | 'shipping_quote_expired' | 'shipping_option_unavailable' | 'shipping_quote_invalid'
      | 'shipping_quote_changed' | 'shipping_postal_code_changed' | 'shipping_option_invalid'
      | 'shipping_price_changed';
  message: string;
}

export interface CheckoutSummary {
  items: CartItem[];
  coupon: CartCoupon | null;
  totals: {
    subtotal_cents: Cents; discount_cents: Cents;
    shipping_cents: Cents | null;       // null enquanto não há opção de frete válida
    shipping_discount_cents: Cents;
    total_cents: Cents;                 // valor a enviar em expected_total_cents
  };
  total_weight_grams: Grams;
  address: Address;
  shipping_option: ShippingOption | null;
  shipping_quote: ShippingQuote | null; // nova cotação quando a informada divergiu
  payment_method: PaymentMethod;
  payment_expires_in_minutes: number;   // settings checkout.pix_expiry_minutes
  billing: OrderDetail['billing'];
  can_place_order: boolean;
  blocking: CheckoutBlocking[];
}

export interface CheckoutResult {
  order: OrderDetail;
  payment: OrderPayment;
  replayed: boolean;
}

export interface CouponIssue {
  code: string;
  reason_code: Exclude<CartCoupon['reason_code'], null>;
  message: string;
}

export interface ReorderReport {
  cart: Cart;
  summary: { total_items: number; added_items: number; adjusted_items: number; skipped_items: number };
  items: {
    sku: string;
    product_name: string;
    result: 'added' | 'adjusted' | 'unavailable' | 'invalid_rules';
    message: string;                    // pt-BR, pronto para exibir
    requested: LineConfiguration;
    added: LineConfiguration | null;
    previous_unit_price_cents: Cents;   // preço no pedido original
    current_unit_price_cents: Cents | null;
    product_url_path: string | null;
  }[];
}

export interface ReorderSuggestion {
  variant_id: number;
  product: Pick<ProductCard, 'id' | 'slug' | 'name' | 'url_path' | 'image' | 'sale_unit' | 'sale_unit_abbr'>;
  variant: { id: number; sku: string; name: string };
  last_configuration: LineConfiguration;
  last_configuration_label: string;
  last_ordered_at: ISODateTime;
  current_unit_price_cents: Cents;
  price_source: PriceSource;
  availability: { status: AvailabilityStatus };
}

export interface PublicSettings {
  store: {
    name: string;
    phone: string | null;
    whatsapp: string | null;            // dígitos; link wa.me montado no front
    email: string | null;
    address: { street: string; number: string; complement: string | null; district: string;
               city: string; state: UF; postal_code: string } | null;
    opening_hours: string | null;
    social_links: { instagram: string | null; facebook: string | null; youtube: string | null };
  };
  pickup_points: (PickupAddress & { method_code: string; name: string })[]; // métodos pickup ativos
  free_shipping_banner: { enabled: boolean; threshold_cents: Cents; text: string } | null;
  terms_version: string;
  checkout: { payment_methods: PaymentMethod[]; pix_expiry_minutes: number; min_order_cents: Cents };
  features: { show_low_stock_quantity: boolean };
}

export interface AppNotification {
  id: UUID;
  type: string;                         // ex.: "order_paid", "low_stock"
  title: string;
  body: string | null;
  link: string | null;                  // path interno da SPA
  read_at: ISODateTime | null;
  created_at: ISODateTime;
}

export type RoleName = 'super-admin' | 'manager' | 'seller' | 'warehouse' | 'finance' | string;

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  roles: RoleName[];
  last_login_at: ISODateTime | null;
  created_at: ISODateTime;
  deleted_at: ISODateTime | null;
}

export interface AdminMe {
  user: AdminUser;
  permissions: PermissionName[];        // efetivas (super-admin recebe a lista completa)
  is_super_admin: boolean;
  session: { idle_timeout_seconds: 1800; absolute_expires_at: ISODateTime };
}

export interface Role {
  id: number;
  name: RoleName;                       // identificador ^[a-z0-9-]{3,50}$
  label: string;                        // pt-BR ("Gerente"); papéis customizados: = name
  is_system: boolean;                   // papéis do seed (super-admin não editável)
  permissions: PermissionName[];
  users_count: number;
}

export interface Permission {
  name: PermissionName;
  label: string;                        // pt-BR
  group: 'dashboard' | 'products' | 'pricing' | 'inventory' | 'orders' | 'payments'
       | 'customers' | 'shipping' | 'reports' | 'admin' | 'settings' | 'audit';
}

export type PermissionName =
  | 'dashboard.view'
  | 'products.view' | 'products.manage'
  | 'prices.manage' | 'pricing.manage' | 'promotions.manage' | 'coupons.manage'
  | 'inventory.view' | 'inventory.move' | 'inventory.adjust'
  | 'orders.view' | 'orders.fulfill' | 'orders.pickup' | 'orders.cancel_unpaid'
  | 'orders.cancel_paid' | 'orders.notes'
  | 'payments.view' | 'payments.reconcile'
  | 'customers.view' | 'customers.view_sensitive' | 'customers.update' | 'customers.manage'
  | 'shipping.manage'
  | 'reports.view' | 'reports.sales' | 'reports.inventory' | 'reports.export'
  | 'admin_users.manage' | 'settings.manage' | 'audit_logs.view';

export interface AdminCategory {
  id: number; parent_id: number | null; name: string; slug: string;
  description_html: string | null; image_url: string | null;
  meta_title: string | null; meta_description: string | null;
  position: number; is_active: boolean; depth: 1 | 2 | 3;
  products_count: number;               // produtos ativos na categoria (N:N)
  children: AdminCategory[];            // presente no endpoint de árvore
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminBrand {
  id: number; name: string; slug: string; logo_url: string | null; is_active: boolean;
  products_count: number; created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminVariant {
  id: number;
  sku: string;
  gtin: string | null;
  name: string;
  attributes: Record<string, string>;
  price_cents: Cents;
  promo_price_cents: Cents | null;
  promo_starts_at: ISODateTime | null;
  promo_ends_at: ISODateTime | null;
  cost_cents: Cents | null;
  weight_grams: Grams;
  package_length_cm: number | null;
  package_width_cm: number | null;
  package_height_cm: number | null;
  roll_length_m: Decimal3 | null;
  units_per_box: number | null;
  units_per_package: number | null;
  fixed_width_m: Decimal3 | null;       // override da variante
  is_active: boolean;
  position: number;
  has_orders: boolean;
  inventory: { on_hand: Decimal3; reserved: Decimal3; available: Decimal3;
               low_stock_threshold: Decimal3; is_low_stock: boolean };
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminProductImage extends ProductImage {
  original_filename: string | null;     // sanitizado, só informativo
  created_at: ISODateTime;
}

export interface AdminProductListItem {
  id: number; name: string; slug: string; sale_unit: SaleUnit;
  primary_category: CategoryRef; brand: BrandRef | null;
  image: ProductImage | null;
  variants_count: number;
  skus: string[];                       // até 5
  min_price_cents: Cents;               // menor preço base entre variantes ativas
  total_available: Decimal3;            // Σ disponível (mesma unidade de estoque)
  has_low_stock: boolean;
  is_active: boolean; is_featured: boolean; pickup_only: boolean;
  updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminProduct {
  id: number;
  name: string;
  slug: string;
  url_path: string;
  short_description: string | null;
  description_html: string | null;
  specifications: { label: string; value: string }[];
  sale_unit: SaleUnit;
  sale_unit_locked: boolean;            // true se já existe pedido com o produto (RN-CAT-004)
  brand_id: number | null;
  primary_category_id: number;
  category_ids: number[];               // inclui a principal
  min_quantity: Decimal3;
  max_quantity: Decimal3 | null;
  quantity_step: Decimal3;
  min_billable_area_m2: Decimal3 | null;
  fixed_width_m: Decimal3 | null;
  min_width_m: Decimal3 | null;
  max_width_m: Decimal3 | null;
  min_height_m: Decimal3 | null;
  max_height_m: Decimal3 | null;
  meta_title: string | null;
  meta_description: string | null;
  is_active: boolean;
  is_featured: boolean;
  pickup_only: boolean;
  activation_issues: string[];          // motivos pelos quais não pode ser ativado (RN-CAT-012); [] = ok
  variants: AdminVariant[];
  images: AdminProductImage[];
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface AdminVariantPickerItem {
  id: number; sku: string; name: string; sale_unit: SaleUnit; price_cents: Cents; is_active: boolean;
  product: { id: number; name: string };
}

export interface PriceTier {
  id: number; variant_id: number; price_list_id: number | null;
  min_quantity: Decimal3; price_cents: Cents;
}

export interface PriceList {
  id: number; code: string; name: string; kind: PriceListKind;
  discount_bp: BasisPoints | null; is_default: boolean; is_active: boolean;
  customers_count: number; companies_count: number; tiers_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface CustomerPrice {
  id: number;
  customer: { id: number; name: string; email: string } | null;
  company: { id: number; legal_name: string } | null;
  variant: AdminVariantPickerItem;
  price_cents: Cents;
  starts_at: ISODateTime | null;
  ends_at: ISODateTime | null;
  created_by: { id: number; name: string } | null;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface Promotion {
  id: number; name: string; description: string | null;
  discount_type: PromotionDiscountType; value: number; // percent: bp; fixed: centavos por unidade de venda
  scope: PromotionScope;
  starts_at: ISODateTime; ends_at: ISODateTime | null;
  is_active: boolean; priority: number;
  status: 'scheduled' | 'active' | 'ended' | 'inactive';
  product_ids: number[]; category_ids: number[]; brand_ids: number[];
  targets: { products: { id: number; name: string }[]; categories: CategoryRef[]; brands: BrandRef[] };
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface Coupon {
  id: number; code: string; description: string | null;
  type: CouponType; value: number;      // percent: bp; fixed: centavos; free_shipping: 0
  min_order_cents: Cents; max_discount_cents: Cents | null;
  starts_at: ISODateTime | null; ends_at: ISODateTime | null;
  usage_limit: number | null; usage_limit_per_customer: number | null;
  times_used: number; is_active: boolean;
  status: 'scheduled' | 'active' | 'expired' | 'exhausted' | 'inactive';
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}
export interface CouponRedemption {
  id: number; order: { id: number; number: string; status: OrderStatus };
  customer: { id: number; name: string }; discount_cents: Cents;
  created_at: ISODateTime; cancelled_at: ISODateTime | null;
}

export interface InventoryItem {
  variant_id: number;
  sku: string;
  variant_name: string;
  product: { id: number; name: string; slug: string; is_active: boolean };
  sale_unit: SaleUnit;
  stock_unit_abbr: string;              // un | m | m² | rolo | kg | cx
  on_hand: Decimal3;
  reserved: Decimal3;
  available: Decimal3;
  low_stock_threshold: Decimal3;        // efetivo (override ?? setting)
  low_stock_threshold_override: Decimal3 | null;
  is_low_stock: boolean;
  low_stock_alerted_at: ISODateTime | null;
  updated_at: ISODateTime | null;
}

export interface InventoryMovement {
  id: number;
  variant_id: number;
  type: InventoryMovementType;
  quantity: Decimal3;                   // magnitude
  on_hand_delta: Decimal3;              // com sinal
  reserved_delta: Decimal3;
  on_hand_after: Decimal3;
  reserved_after: Decimal3;
  reason: string | null;
  reference: { type: 'order'; id: number; label: string } | null; // label = número do pedido
  actor: { type: ActorType; id: number | null; name: string | null };
  created_at: ISODateTime;
}

export interface AdminPaymentTransaction {
  id: number;
  type: 'create' | 'approve' | 'fail' | 'refund' | 'expire' | 'cancel' | 'sync';
  status_before: PaymentRecordStatus | null;
  status_after: PaymentRecordStatus;
  amount_cents: Cents;
  external_id: string | null;
  admin_user: { id: number; name: string } | null;
  created_at: ISODateTime;
}

export interface AdminPayment {
  id: number; uuid: UUID; provider: PaymentProvider; method: PaymentMethod;
  status: PaymentRecordStatus; amount_cents: Cents; refunded_cents: Cents;
  external_id: string | null; expires_at: ISODateTime | null; paid_at: ISODateTime | null;
  failed_at: ISODateTime | null; refunded_at: ISODateTime | null; failure_reason: string | null;
  refund: { status: 'pending' | 'succeeded' | 'failed'; amount_cents: Cents; requested_at: ISODateTime } | null;
  transactions: AdminPaymentTransaction[] | null; // null sem payments.view
  created_at: ISODateTime;
}

export interface AdminOrderListItem {
  id: number; uuid: UUID; number: string;
  status: OrderStatus; payment_status: OrderPaymentStatus; payment_method: PaymentMethod;
  customer: { id: number; name: string; type: CustomerType; company_name: string | null };
  items_count: number; total_cents: Cents;
  shipping_method_name: string; shipping_method_type: ShippingMethodType;
  shipping_city: string | null; shipping_state: UF | null;
  placed_at: ISODateTime; paid_at: ISODateTime | null; expires_at: ISODateTime | null;
  has_cancellation_request: boolean;
}

export interface AdminTransition {
  to_status: OrderStatus;
  label: string;                        // "Marcar em separação", "Marcar como enviado"...
  required_fields: ('tracking_code' | 'picked_up_by_name' | 'picked_up_by_document')[];
  optional_fields: ('note' | 'tracking_code' | 'tracking_url' | 'carrier_name')[];
}

export interface AdminOrderItem extends OrderItem {
  id: number;
  variant_id: number;
  picking_instruction: string;          // "Separar: 5 m" | "Cortar: 4 peças de 1,20 × 2,50 m"
}

export interface AdminStatusHistoryEntry {
  id: number;
  from_status: OrderStatus | null;
  to_status: OrderStatus;
  actor: { type: ActorType; id: number | null; name: string | null };
  note: string | null;
  created_at: ISODateTime;
}

export interface AdminOrder {
  id: number; uuid: UUID; number: string;
  status: OrderStatus; status_label: string;
  payment_status: OrderPaymentStatus; payment_method: PaymentMethod;
  placed_at: ISODateTime; expires_at: ISODateTime | null; paid_at: ISODateTime | null;
  processing_at: ISODateTime | null; shipped_at: ISODateTime | null;
  ready_for_pickup_at: ISODateTime | null; delivered_at: ISODateTime | null;
  picked_up_at: ISODateTime | null; cancelled_at: ISODateTime | null; refunded_at: ISODateTime | null;
  cancel_reason_code: CancelReasonCode | null; cancel_reason: string | null;
  cancellation_request: { requested_at: ISODateTime; reason: string | null } | null;
  customer: {
    id: number; uuid: UUID; type: CustomerType; name: string; email: string; phone: string | null;
    document_masked: string;            // "***.456.789-**" / "**.345.678/0001-**"
    company_name: string | null; state_registration: string | null;
  };
  items: AdminOrderItem[];
  totals: OrderDetail['totals'];
  coupon: { id: number; code: string } | null;
  total_weight_grams: Grams;
  total_volume_cm3: number;
  shipping: OrderShippingSnapshot & {
    method_id: number | null; rule_id: number | null; option_id: string; quote_uuid: UUID | null;
    picked_up_by_document_masked: string | null;
  };
  payments: AdminPayment[];             // mais recente primeiro
  status_history: AdminStatusHistoryEntry[];
  notes: string | null;                 // do cliente
  internal_notes: string | null;
  allowed_transitions: AdminTransition[]; // válidas na máquina E permitidas ao admin atual
  can_cancel: boolean;                  // estado permite e admin tem a permissão correspondente
  cancel_requires_refund: boolean;      // status paid|processing
  flags: { amount_mismatch: boolean };  // PaymentAmountMismatch registrado
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface AdminCompany {
  id: number; legal_name: string; trade_name: string | null;
  cnpj_masked: string; state_registration: string | null; state_registration_exempt: boolean;
  price_list: { id: number; code: string; name: string } | null;
  customers: { id: number; name: string; email: string }[];
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface AdminCustomer {
  id: number; uuid: UUID; type: CustomerType; name: string; email: string;
  email_verified_at: ISODateTime | null; phone: string | null;
  cpf_masked: string | null;
  company: AdminCompany | null;
  price_list: { id: number; code: string; name: string } | null;        // atribuída ao cliente
  effective_price_list: { id: number; code: string; name: string } | null; // cliente → empresa → default
  is_active: boolean; marketing_opt_in: boolean;
  terms_version: string; terms_accepted_at: ISODateTime;
  last_login_at: ISODateTime | null; anonymized_at: ISODateTime | null;
  stats: { orders_count: number; paid_orders_count: number; total_spent_cents: Cents; last_order_at: ISODateTime | null };
  addresses?: Address[];                // só no detalhe
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface Carrier {
  id: number; name: string; code: string; driver: string;
  settings: Record<string, string | number | boolean | null>;
  has_credentials: boolean;             // credentials nunca são retornadas
  is_active: boolean; methods_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface ShippingMethod {
  id: number; name: string; code: string; type: ShippingMethodType;
  carrier_id: number | null; carrier_service_code: string | null;
  description: string | null;
  delivery_days_min: number; delivery_days_max: number; handling_days: number;
  weight_basis: WeightBasis; cubic_divisor: number | null;
  accepts_free_shipping_coupon: boolean;
  position: number; is_active: boolean;
  pickup: {
    street: string; number: string | null; complement: string | null; district: string | null;
    city: string; state: UF; postal_code: string; instructions: string | null; opening_hours: string | null;
  } | null;                             // só type = pickup
  rules_count: number;
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface ShippingZone {
  id: number; name: string; description: string | null; is_active: boolean;
  postal_ranges: { id: number; start_postal_code: string; end_postal_code: string }[];
  cities: { id: number; city_ibge_code: string; city_name: string; state: UF }[];
  states: UF[];
  rules_count: number;
  created_at: ISODateTime; updated_at: ISODateTime;
}

export interface ShippingRule {
  id: number; method_id: number; zone_id: number | null; name: string; priority: number;
  min_weight_grams: Grams | null; max_weight_grams: Grams | null;
  min_subtotal_cents: Cents | null; max_subtotal_cents: Cents | null;
  min_volume_cm3: number | null; max_volume_cm3: number | null;
  max_package_length_cm: number | null;
  price_type: ShippingPriceType;
  price_cents: Cents; per_kg_cents: Cents; percentage_bp: BasisPoints;
  min_price_cents: Cents | null; max_price_cents: Cents | null;
  delivery_days_min: number | null; delivery_days_max: number | null;
  valid_from: ISODateTime | null; valid_until: ISODateTime | null;
  is_active: boolean;
  summary: string;                      // "Blumenau · ≤ 10 kg → R$ 20,00"
  created_at: ISODateTime; updated_at: ISODateTime; deleted_at: ISODateTime | null;
}

export interface IbgeCity { ibge_code: string; name: string; state: UF }

export type SettingKey =
  | 'store.name' | 'store.legal_name' | 'store.document' | 'store.address' | 'store.phone'
  | 'store.whatsapp' | 'store.email' | 'store.opening_hours' | 'store.social_links'
  | 'orders.number_prefix' | 'checkout.pix_expiry_minutes' | 'checkout.min_order_cents'
  | 'cart.guest_ttl_days' | 'shipping.quote_ttl_minutes' | 'shipping.origin_postal_code'
  | 'inventory.default_low_stock_threshold' | 'inventory.show_low_stock_quantity'
  | 'legal.terms_version' | 'storefront.free_shipping_banner'
  | 'notifications.whatsapp_enabled' | 'notifications.admin_alert_emails'
  | 'content.about' | 'content.terms' | 'content.privacy' | 'content.returns';

export interface Setting {
  key: SettingKey;
  value: unknown;                       // tipo conforme tabela da seção 3.G.13
  type: 'string' | 'integer' | 'decimal' | 'boolean' | 'object' | 'string_list' | 'text';
  group: 'store' | 'checkout' | 'shipping' | 'inventory' | 'legal' | 'storefront' | 'notifications' | 'content';
  is_public: boolean;
  description: string | null;
  updated_at: ISODateTime | null;
  updated_by: { id: number; name: string } | null;
}

export interface AuditLog {
  id: number;
  actor: { type: ActorType; id: number | null; name: string | null };
  action: string;                       // "product.updated", "order.status_changed", "inventory.adjusted"
  auditable_type: string | null;        // morph alias: "product", "order"...
  auditable_id: number | null;
  auditable_label: string | null;       // "CV-000123", "Vinil Adesivo Branco"
  old_values: Record<string, unknown> | null; // sensíveis já mascarados ("••••")
  new_values: Record<string, unknown> | null;
  ip: string | null;
  user_agent: string | null;
  request_id: string | null;
  created_at: ISODateTime;
}

export interface KpiValue { value: number; previous: number; change_bp: number | null } // change em bp (1250 = +12,5%)

export interface Dashboard {
  generated_at: ISODateTime;
  sales: {                              // null sem reports.view|reports.sales
    today: { orders_paid: KpiValue; revenue_cents: KpiValue };
    month: { orders_paid: KpiValue; revenue_cents: KpiValue };
    avg_ticket_cents_month: KpiValue;
    revenue_series_30d: { date: ISODate; revenue_cents: Cents; orders_paid: number }[]; // 30 pontos, dias sem venda = 0
    top_products_30d: { variant_id: number; sku: string; name: string; sale_unit: SaleUnit;
                        quantity: Decimal3; revenue_cents: Cents }[]; // top 5
  } | null;
  queues: {                             // null sem orders.view
    pending_payment: number; to_pick: number;          // paid
    to_ship: number;                                    // processing com entrega
    to_prepare_pickup: number;                          // processing com retirada
    ready_for_pickup: number; shipped: number; cancellation_requests: number;
  } | null;
  todays_deliveries: {                  // null sem orders.view
    order_id: number; number: string; status: OrderStatus; shipping_method_type: ShippingMethodType;
    shipping_method_name: string; district: string | null; city: string | null;
    estimated_delivery_date: ISODate | null;
  }[] | null;
  low_stock: { items: InventoryItem[]; total: number } | null; // top 10; null sem inventory.view
}

export interface ReportResponse<Row, Summary = Record<string, number | null>> {
  report: ReportName;
  period: { date_from: ISODate; date_to: ISODate; group_by: 'day' | 'week' | 'month' | null; timezone: 'America/Sao_Paulo' };
  filters: Record<string, string | number | null>;
  summary: Summary;
  rows: Row[];
  totals: Partial<Row> | null;
}
export type ReportName =
  | 'sales' | 'products' | 'revenue' | 'customers' | 'inventory' | 'inventory-movements'
  | 'orders' | 'shipping' | 'margin' | 'coupons';

export interface SalesReportRow {       // report=sales (RN-REL-001/005/006)
  period_start: ISODate; orders_paid: number; orders_refunded: number;
  revenue_cents: Cents;                 // Σ total_cents pagos no período, excluindo refunded
  products_revenue_cents: Cents;        // Σ subtotal − discount
  shipping_cents: Cents; discount_cents: Cents; avg_ticket_cents: Cents | null;
}
export interface ProductsReportRow {    // report=products (RN-REL-010)
  variant_id: number; sku: string; product_name: string; variant_name: string;
  sale_unit: SaleUnit; quantity: Decimal3; revenue_cents: Cents; orders_count: number;
}
export interface RevenueReportRow {     // report=revenue
  period_start: ISODate; gross_cents: Cents; discount_cents: Cents; shipping_cents: Cents;
  refunds_cents: Cents; net_cents: Cents;
}
export interface CustomersReportRow {   // report=customers (top clientes)
  customer_id: number; name: string; type: CustomerType; orders_count: number;
  revenue_cents: Cents; last_order_at: ISODateTime;
}
export interface InventoryReportRow {   // report=inventory (posição atual)
  variant_id: number; sku: string; product_name: string; sale_unit: SaleUnit;
  on_hand: Decimal3; reserved: Decimal3; available: Decimal3; low_stock_threshold: Decimal3;
  is_low_stock: boolean; cost_cents: Cents | null; stock_value_cents: Cents | null;
  sold_quantity: Decimal3;              // vendido no período
}
export interface InventoryMovementsReportRow { // report=inventory-movements (RN-REL-015)
  variant_id: number; sku: string; product_name: string; type: InventoryMovementType;
  movements_count: number; quantity: Decimal3;
}
export interface OrdersReportRow {      // report=orders (RN-REL-007/008/012/013)
  status: OrderStatus; count: number;
}
export interface ShippingReportRow {    // report=shipping (RN-REL-017)
  shipping_method_id: number | null; method_name: string; method_type: ShippingMethodType;
  city: string | null; state: UF | null; orders_count: number; shipping_revenue_cents: Cents;
  free_shipping_orders: number; shipping_discount_cents: Cents;
}
export interface MarginReportRow {      // report=margin
  variant_id: number; sku: string; product_name: string; sale_unit: SaleUnit; quantity: Decimal3;
  revenue_cents: Cents; cost_cents: Cents | null; margin_cents: Cents | null; margin_bp: BasisPoints | null;
}
export interface CouponsReportRow {     // report=coupons (RN-REL-009)
  coupon_id: number; code: string; uses: number; discount_cents: Cents; orders_revenue_cents: Cents;
}

