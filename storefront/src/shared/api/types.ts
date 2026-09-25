// Tipos do contrato HTTP — espelho de docs/API.md §2 (subconjunto usado pela loja).
// `T | null` = campo sempre presente, podendo ser null. `campo?:` = pode estar ausente.

export type Cents = number;
export type BasisPoints = number;
export type Decimal3 = number;
export type Grams = number;
export type ISODateTime = string;
export type ISODate = string;
export type UUID = string;

export type SaleUnit = 'UNIT' | 'LINEAR_METER' | 'SQUARE_METER' | 'ROLL' | 'KG' | 'BOX';
export type PriceSource =
  | 'base'
  | 'tier'
  | 'price_list'
  | 'variant_promo'
  | 'promotion'
  | 'customer_price';
export type AvailabilityStatus = 'in_stock' | 'low_stock' | 'out_of_stock';
export type CustomerType = 'individual' | 'company';
export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'ready_for_pickup'
  | 'picked_up'
  | 'cancelled';
export type OrderPaymentStatus = 'pending' | 'approved' | 'failed' | 'refunded' | 'expired';
export type PaymentRecordStatus =
  | 'pending'
  | 'approved'
  | 'failed'
  | 'expired'
  | 'cancelled'
  | 'refunded'
  | 'partially_refunded';
export type PaymentMethod = 'pix' | 'credit_card' | 'boleto' | 'invoice';
export type CancelReasonCode = 'payment_expired' | 'customer' | 'admin' | 'payment_failed';
export type ShippingMethodType = 'pickup' | 'own_delivery' | 'table_rate' | 'carrier';
export type FreeShippingReason = 'rule' | 'coupon';
export type CouponType = 'percent' | 'fixed' | 'free_shipping';
export type UF =
  | 'AC' | 'AL' | 'AP' | 'AM' | 'BA' | 'CE' | 'DF' | 'ES' | 'GO' | 'MA' | 'MT' | 'MS' | 'MG' | 'PA'
  | 'PB' | 'PR' | 'PE' | 'PI' | 'RJ' | 'RN' | 'RS' | 'RO' | 'RR' | 'SC' | 'SP' | 'SE' | 'TO';

// §1.4
export interface Paginated<T> {
  data: T[];
  links: { first: string; last: string; prev: string | null; next: string | null };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
  };
}

export interface DataEnvelope<T> {
  data: T;
}

// §2.2
export interface CategoryRef {
  id: number;
  name: string;
  slug: string;
  url_path: string;
}
export interface BrandRef {
  id: number;
  name: string;
  slug: string;
}
export interface Breadcrumb {
  name: string;
  url_path: string | null;
}
export interface ImageUrls {
  w300: string;
  w800: string;
  w1600: string;
}
export interface ProductImage {
  id: number;
  urls: ImageUrls | null;
  alt: string;
  width: number | null;
  height: number | null;
  position: number;
  variant_id: number | null;
}
export interface Seo {
  title: string;
  description: string;
  canonical_path: string;
  canonical_url: string;
  robots: 'index,follow' | 'noindex,follow' | 'noindex,nofollow';
  og_image_url: string | null;
  json_ld: Record<string, unknown>[];
}

// §2.3
export interface SaleUnitRules {
  sale_unit: SaleUnit;
  input: 'integer' | 'decimal' | 'dimensions';
  min_quantity: Decimal3;
  max_quantity: Decimal3 | null;
  quantity_step: Decimal3;
  fixed_width_m: Decimal3 | null;
  min_width_m: Decimal3 | null;
  max_width_m: Decimal3 | null;
  min_height_m: Decimal3 | null;
  max_height_m: Decimal3 | null;
  min_billable_area_m2: Decimal3 | null;
  dimension_decimals: 2;
  max_pieces: 1000;
}
export interface PriceTierDisplay {
  min_quantity: Decimal3;
  max_quantity: Decimal3 | null;
  unit_price_cents: Cents;
  price_source: PriceSource;
}
export interface VariantPrice {
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  compare_at_cents: Cents | null;
  price_source: PriceSource;
  price_source_label: string | null;
  promotion: { name: string; ends_at: ISODateTime | null } | null;
  tiers: PriceTierDisplay[];
}
export interface Availability {
  status: AvailabilityStatus;
  available_quantity: Decimal3 | null;
}
export interface ProductCard {
  id: number;
  slug: string;
  name: string;
  url_path: string;
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  image: ProductImage | null;
  variants_count: number;
  default_variant: { id: number; sku: string; name: string };
  key_attribute: string | null;
  price: {
    unit_price_cents: Cents;
    compare_at_cents: Cents | null;
    price_source: PriceSource;
    price_source_label: string | null;
    from_price_cents: Cents | null;
  };
  availability: { status: AvailabilityStatus };
  pickup_only: boolean;
  is_featured: boolean;
  quick_add: boolean;
}
export interface ProductVariant {
  id: number;
  sku: string;
  gtin: string | null;
  name: string;
  attributes: Record<string, string>;
  position: number;
  is_default: boolean;
  image_ids: number[];
  rules: SaleUnitRules;
  price: VariantPrice;
  availability: Availability;
  weight_grams: Grams;
  roll_length_m: Decimal3 | null;
  units_per_box: number | null;
}
export interface ProductDetail {
  id: number;
  slug: string;
  name: string;
  url_path: string;
  short_description: string | null;
  description_html: string | null;
  specifications: { label: string; value: string }[];
  sale_unit: SaleUnit;
  sale_unit_label: string;
  sale_unit_abbr: string;
  brand: BrandRef | null;
  primary_category: CategoryRef;
  categories: CategoryRef[];
  breadcrumbs: Breadcrumb[];
  images: ProductImage[];
  attribute_axes: { key: string; label: string; values: string[] }[];
  variants: ProductVariant[];
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
  children: CategoryNode[];
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
export interface Brand {
  id: number;
  name: string;
  slug: string;
  logo_url: string | null;
}
export interface ProductFacets {
  categories: { slug: string; name: string; count: number }[];
  brands: { slug: string; name: string; count: number }[];
  sale_units: { value: SaleUnit; label: string; count: number }[];
  price_range_cents: { min: Cents; max: Cents } | null;
}
export type ProductListResponse = Paginated<ProductCard> & {
  facets: ProductFacets;
  search: {
    q: string;
    exact_sku_match: { variant_id: number; sku: string; product_slug: string; url_path: string } | null;
  } | null;
};
export interface AutocompleteResult {
  products: {
    id: number;
    name: string;
    slug: string;
    url_path: string;
    image_url: string | null;
    matched_sku: string | null;
    unit_price_cents: Cents;
    sale_unit_abbr: string;
  }[];
  categories: CategoryRef[];
  brands: BrandRef[];
}

// §2.4
export interface LineConfiguration {
  quantity: Decimal3 | null;
  width_m: Decimal3 | null;
  height_m: Decimal3 | null;
  pieces: number | null;
}
export interface PricePreview {
  variant_id: number;
  sale_unit: SaleUnit;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3;
  stock_quantity: Decimal3;
  piece_area_m2: Decimal3 | null;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  compare_at_cents: Cents | null;
  price_source: PriceSource;
  price_source_label: string | null;
  line_total_cents: Cents;
  weight_grams: Grams;
  applied_tier: PriceTierDisplay | null;
  next_tier: (PriceTierDisplay & { missing_quantity: Decimal3 }) | null;
  stock: { sufficient: boolean; available_quantity: Decimal3 | null };
}

/** Corpo aceito por price-preview / POST /cart/items / itens de /shipping/quote. */
export type LineInput =
  | { variant_id: number; quantity: Decimal3 }
  | { variant_id: number; width_m?: Decimal3; height_m: Decimal3; pieces: number };

// §2.5
export type CartItemStatus = 'ok' | 'unavailable' | 'insufficient_stock' | 'invalid_quantity';
export type CartItemWarning =
  | { code: 'price_changed'; previous_unit_price_cents: Cents; current_unit_price_cents: Cents }
  | { code: 'unavailable'; message: string }
  | {
      code: 'insufficient_stock';
      requested_quantity: Decimal3;
      available_quantity: Decimal3;
      message: string;
    }
  | { code: 'invalid_quantity'; message: string; suggestions: number[] }
  | { code: 'min_area_applied'; area_m2: Decimal3; billable_area_m2: Decimal3 };

export interface CartItem {
  id: number;
  variant_id: number;
  product: { id: number; slug: string; name: string; url_path: string; image: ProductImage | null };
  variant: { id: number; sku: string; name: string; attributes: Record<string, string> };
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3 | null;
  stock_quantity: Decimal3 | null;
  piece_area_m2: Decimal3 | null;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;
  unit_price_cents: Cents | null;
  base_unit_price_cents: Cents | null;
  compare_at_cents: Cents | null;
  price_source: PriceSource | null;
  price_source_label: string | null;
  line_total_cents: Cents | null;
  weight_grams: Grams;
  status: CartItemStatus;
  warnings: CartItemWarning[];
  rules: SaleUnitRules | null;
  availability: Availability;
}
export type CouponReasonCode =
  | null
  | 'login_required'
  | 'expired'
  | 'inactive'
  | 'min_order_not_met'
  | 'usage_limit_reached'
  | 'customer_limit_reached'
  | 'not_found';
export interface CartCoupon {
  code: string;
  description: string | null;
  type: CouponType;
  valid: boolean;
  reason_code: CouponReasonCode;
  message: string | null;
  discount_cents: Cents;
  free_shipping: boolean;
}
export type CartBlockingReason =
  | 'cart_empty'
  | 'item_unavailable'
  | 'item_insufficient_stock'
  | 'item_invalid_quantity'
  | 'coupon_invalid';
export interface Cart {
  token: UUID | null;
  owner: 'guest' | 'customer';
  items: CartItem[];
  items_count: number;
  coupon: CartCoupon | null;
  postal_code: string | null;
  totals: {
    subtotal_cents: Cents;
    discount_cents: Cents;
    shipping_cents: Cents | null;
    shipping_discount_cents: Cents | null;
    total_cents: Cents;
  };
  shipping_selection: {
    quote_id: UUID;
    option_id: string;
    option: ShippingOption;
    valid: boolean;
    issue_code: string | null;
  } | null;
  total_weight_grams: Grams;
  free_shipping_progress: { threshold_cents: Cents; remaining_cents: Cents; text: string } | null;
  price_list: { code: string; name: string } | null;
  can_checkout: boolean;
  blocking_reasons: CartBlockingReason[];
  has_price_changes: boolean;
  updated_at: ISODateTime | null;
}
export interface CartMergeReport {
  merged: boolean;
  lines_added: number;
  lines_combined: number;
  adjustments: {
    variant_id: number;
    sku: string;
    product_name: string;
    previous_quantity: Decimal3;
    quantity: Decimal3;
    reason: 'max_quantity' | 'insufficient_stock';
  }[];
  dropped: {
    variant_id: number;
    sku: string;
    product_name: string;
    reason: 'unavailable' | 'line_limit' | 'invalid_quantity';
  }[];
  coupon: { code: string; kept: boolean; reason_code: CouponReasonCode } | null;
}
export interface CartItemIssue {
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
  requested_quantity: Decimal3;
  available_quantity: Decimal3;
}

// §2.6
export interface PickupAddress {
  street: string;
  number: string;
  complement: string | null;
  district: string;
  city: string;
  state: UF;
  postal_code: string;
  opening_hours: string | null;
  instructions: string | null;
}
export interface ShippingOption {
  option_id: string;
  method_code: string;
  method_type: ShippingMethodType;
  name: string;
  description: string | null;
  price_cents: Cents;
  original_price_cents: Cents;
  is_free: boolean;
  free_reason: FreeShippingReason | null;
  delivery_days_min: number;
  delivery_days_max: number;
  delivery_label: string;
  carrier: {
    code: string;
    name: string;
    service_code: string | null;
    service_name: string | null;
  } | null;
  pickup_address: PickupAddress | null;
}
export interface ShippingQuote {
  quote_id: UUID | null;
  expires_at: ISODateTime | null;
  destination: { postal_code: string; city: string | null; state: UF | null };
  options: ShippingOption[];
  notice: 'pickup_only_items' | null;
  message: string | null;
  total_weight_grams: Grams;
}
export interface PostalCodeInfo {
  postal_code: string;
  street: string | null;
  district: string | null;
  city: string;
  state: UF;
  city_ibge_code: string;
  source: 'viacep' | 'cache';
}

// §2.7
export interface Company {
  legal_name: string;
  trade_name: string | null;
  cnpj: string;
  state_registration: string | null;
  state_registration_exempt: boolean;
}
export interface Customer {
  uuid: UUID;
  type: CustomerType;
  name: string;
  email: string;
  email_verified: boolean;
  phone: string | null;
  cpf: string | null;
  marketing_opt_in: boolean;
  company: Company | null;
  price_list: { code: string; name: string } | null;
  terms: { accepted_version: string; current_version: string; needs_acceptance: boolean };
  profile_complete: boolean;
  missing_fields: string[];
  created_at: ISODateTime;
}
export interface Address {
  uuid: UUID;
  label: string | null;
  recipient_name: string;
  phone: string | null;
  postal_code: string;
  street: string;
  number: string;
  complement: string | null;
  district: string;
  city: string;
  state: UF;
  city_ibge_code: string | null;
  reference: string | null;
  is_default: boolean;
  formatted: string;
  created_at: ISODateTime;
}
export interface AddressInput {
  postal_code: string;
  street: string;
  number: string;
  complement?: string | null;
  district: string;
  reference?: string | null;
  recipient_name: string;
  phone: string;
  label?: string | null;
  is_default?: boolean;
}

// §2.8
export interface PixData {
  qr_code_base64: string | null;
  copy_paste: string;
  expires_at: ISODateTime;
}
export interface OrderPayment {
  uuid: UUID;
  method: PaymentMethod;
  status: PaymentRecordStatus;
  amount_cents: Cents;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  pix: PixData | null;
}
export interface OrderItem {
  product_id: number;
  product_name: string;
  variant_name: string;
  sku: string;
  product_url_path: string | null;
  sale_unit: SaleUnit;
  sale_unit_abbr: string;
  configuration: LineConfiguration;
  configuration_label: string;
  billable_quantity: Decimal3;
  stock_quantity: Decimal3;
  area_m2: Decimal3 | null;
  min_area_applied: boolean;
  unit_price_cents: Cents;
  base_unit_price_cents: Cents;
  price_source: PriceSource;
  subtotal_cents: Cents;
  discount_cents: Cents;
  total_cents: Cents;
  weight_grams: Grams;
}
export interface OrderTimelineEntry {
  status: OrderStatus;
  status_label: string;
  occurred_at: ISODateTime;
  note: string | null;
}
export interface OrderAllowedActions {
  can_pay: boolean;
  can_retry_payment: boolean;
  can_cancel: boolean;
  can_request_cancellation: boolean;
  can_reorder: boolean;
}
export interface OrderSummary {
  uuid: UUID;
  number: string;
  status: OrderStatus;
  status_label: string;
  payment_status: OrderPaymentStatus;
  payment_method: PaymentMethod;
  placed_at: ISODateTime;
  expires_at: ISODateTime | null;
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
  estimated_delivery_date: ISODate | null;
  tracking_code: string | null;
  tracking_url: string | null;
  address: {
    recipient_name: string;
    phone: string | null;
    postal_code: string;
    street: string;
    number: string;
    complement: string | null;
    district: string;
    city: string;
    state: UF;
    reference: string | null;
    formatted: string;
  } | null;
  pickup_address: PickupAddress | null;
  picked_up_at: ISODateTime | null;
  picked_up_by_name: string | null;
}
export interface OrderBilling {
  customer_type: CustomerType;
  name: string;
  email: string;
  document: string;
  phone: string | null;
  company_name: string | null;
  state_registration: string | null;
}
export interface OrderDetail extends OrderSummary {
  paid_at: ISODateTime | null;
  cancelled_at: ISODateTime | null;
  cancel_reason_code: CancelReasonCode | null;
  cancel_reason_label: string | null;
  cancellation_request: { requested_at: ISODateTime; reason: string | null } | null;
  items: OrderItem[];
  totals: {
    subtotal_cents: Cents;
    discount_cents: Cents;
    shipping_cents: Cents;
    shipping_discount_cents: Cents;
    total_cents: Cents;
  };
  coupon_code: string | null;
  total_weight_grams: Grams;
  billing: OrderBilling;
  shipping: OrderShippingSnapshot;
  payment: OrderPayment | null;
  timeline: OrderTimelineEntry[];
  notes: string | null;
}
export interface OrderStatusPoll {
  uuid: UUID;
  number: string;
  status: OrderStatus;
  payment_status: OrderPaymentStatus;
  expires_at: ISODateTime | null;
  paid_at: ISODateTime | null;
  payment: {
    uuid: UUID;
    status: PaymentRecordStatus;
    expires_at: ISODateTime | null;
    has_pix: boolean;
  } | null;
  updated_at: ISODateTime;
}

// §2.9
export type CheckoutBlockingCode =
  | 'cart_empty'
  | 'cart_invalid'
  | 'insufficient_stock'
  | 'coupon_invalid'
  | 'shipping_required'
  | 'profile_incomplete'
  | 'too_many_pending_orders'
  | 'shipping_quote_expired'
  | 'shipping_option_unavailable'
  | 'shipping_quote_invalid'
  | 'shipping_quote_changed'
  | 'shipping_postal_code_changed'
  | 'shipping_option_invalid'
  | 'shipping_price_changed';
export interface CheckoutBlocking {
  code: CheckoutBlockingCode;
  message: string;
}
export interface CheckoutSummary {
  items: CartItem[];
  coupon: CartCoupon | null;
  totals: {
    subtotal_cents: Cents;
    discount_cents: Cents;
    shipping_cents: Cents | null;
    shipping_discount_cents: Cents;
    total_cents: Cents;
  };
  total_weight_grams: Grams;
  address: Address;
  shipping_option: ShippingOption | null;
  shipping_quote: ShippingQuote | null;
  payment_method: PaymentMethod;
  payment_expires_in_minutes: number;
  billing: OrderBilling;
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
  reason_code: Exclude<CouponReasonCode, null>;
  message: string;
}
/** Corpo aceito por POST /checkout (whitelist §3.E). Não existe campo de preço aqui por design. */
export interface CheckoutRequest {
  address_uuid: UUID;
  shipping_quote_id: UUID;
  shipping_option_id: string;
  payment_method: 'pix';
  expected_total_cents: Cents;
  notes: string | null;
  accept_terms: true;
}

// §2.10
export interface ReorderReport {
  cart: Cart;
  summary: { total_items: number; added_items: number; adjusted_items: number; skipped_items: number };
  items: {
    sku: string;
    product_name: string;
    result: 'added' | 'adjusted' | 'unavailable' | 'invalid_rules';
    message: string;
    requested: LineConfiguration;
    added: LineConfiguration | null;
    previous_unit_price_cents: Cents;
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

// §2.11
export interface PublicSettings {
  store: {
    name: string;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    address: {
      street: string;
      number: string;
      complement: string | null;
      district: string;
      city: string;
      state: UF;
      postal_code: string;
    } | null;
    opening_hours: string | null;
    social_links: { instagram: string | null; facebook: string | null; youtube: string | null };
  };
  pickup_points: (PickupAddress & { method_code: string; name: string })[];
  free_shipping_banner: { enabled: boolean; threshold_cents: Cents; text: string } | null;
  terms_version: string;
  checkout: { payment_methods: PaymentMethod[]; pix_expiry_minutes: number; min_order_cents: Cents };
  features: { show_low_stock_quantity: boolean };
}
export interface InstitutionalPage {
  slug: string;
  title: string;
  body_text: string;
  updated_at: ISODateTime | null;
}

// §1.6 — erros
export type ErrorCode =
  | 'unauthenticated'
  | 'admin_session_expired'
  | 'forbidden'
  | 'account_disabled'
  | 'not_found'
  | 'cart_not_found'
  | 'insufficient_stock'
  | 'price_changed'
  | 'shipping_quote_expired'
  | 'shipping_option_unavailable'
  | 'shipping_quote_invalid'
  | 'shipping_quote_changed'
  | 'shipping_postal_code_changed'
  | 'shipping_option_invalid'
  | 'shipping_price_changed'
  | 'coupon_invalid'
  | 'idempotency_conflict'
  | 'invalid_status_transition'
  | 'cart_empty'
  | 'cart_invalid'
  | 'too_many_pending_orders'
  | 'resource_in_use'
  | 'stale_resource'
  | 'payload_too_large'
  | 'csrf_token_mismatch'
  | 'too_many_requests'
  | 'server_error'
  | 'payment_gateway_unavailable'
  | 'postal_code_lookup_unavailable'
  | 'service_unavailable';

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
