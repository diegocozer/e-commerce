/* Dados de demonstração para MSW (VITE_USE_MOCKS=true) e testes. Números do seed de DATABASE §7. */
import type {
  AdminBrand,
  AdminCategory,
  AdminCompany,
  AdminCustomer,
  AdminMe,
  AdminOrder,
  AdminOrderItem,
  AdminProduct,
  AdminUser,
  AdminVariant,
  AuditLog,
  Carrier,
  Coupon,
  CustomerPrice,
  InventoryMovement,
  OrderStatus,
  PriceList,
  PriceTier,
  Promotion,
  Role,
  SaleUnit,
  Setting,
  ShippingMethod,
  ShippingRule,
  ShippingZone,
} from '@/shared/api/types';
import { ALL_PERMISSIONS } from '@/shared/auth/permissions';

const T0 = '2026-09-20T12:00:00Z';
const ts = { created_at: T0, updated_at: T0 };

export function makeAdminUser(over: Partial<AdminUser> = {}): AdminUser {
  return { id: 1, name: 'Carla Gerente', email: 'admin@comunika.test', is_active: true, roles: ['super-admin'], last_login_at: T0, created_at: T0, deleted_at: null, ...over };
}

export function makeMe(over: Partial<AdminMe> = {}): AdminMe {
  return {
    user: makeAdminUser(),
    permissions: [...ALL_PERMISSIONS],
    is_super_admin: true,
    session: { idle_timeout_seconds: 1800, absolute_expires_at: '2026-09-25T20:00:00Z' },
    ...over,
  };
}

const cat = (id: number, parent_id: number | null, name: string, slug: string, depth: 1 | 2 | 3, children: AdminCategory[] = []): AdminCategory => ({
  id, parent_id, name, slug, description_html: null, image_url: null, meta_title: null, meta_description: null,
  position: id, is_active: true, depth, products_count: 1, children, ...ts, deleted_at: null,
});

export function seedCategories(): AdminCategory[] {
  return [
    cat(1, null, 'Vinis', 'vinis', 1, [cat(4, 1, 'Vinil adesivo', 'vinil-adesivo', 2)]),
    cat(2, null, 'Lonas', 'lonas', 1),
    cat(3, null, 'Acessórios', 'acessorios', 1),
  ];
}

export function seedBrands(): AdminBrand[] {
  return [
    { id: 1, name: 'VinilSul', slug: 'vinilsul', logo_url: null, is_active: true, products_count: 1, ...ts, deleted_at: null },
    { id: 2, name: 'Imprimax', slug: 'imprimax', logo_url: null, is_active: true, products_count: 1, ...ts, deleted_at: null },
  ];
}

export function makeVariant(id: number, over: Partial<AdminVariant> = {}): AdminVariant {
  return {
    id, sku: `SKU-${id}`, gtin: null, name: 'Padrão', attributes: {}, price_cents: 1590, promo_price_cents: null, promo_starts_at: null, promo_ends_at: null,
    cost_cents: 900, weight_grams: 250, package_length_cm: 125, package_width_cm: 10, package_height_cm: 10, roll_length_m: null, units_per_box: null,
    units_per_package: null, fixed_width_m: null, is_active: true, position: 0, has_orders: false,
    inventory: { on_hand: 450, reserved: 30, available: 420, low_stock_threshold: 100, is_low_stock: false },
    ...ts, deleted_at: null, ...over,
  };
}

export function makeProduct(id: number, sale_unit: SaleUnit, over: Partial<AdminProduct> = {}): AdminProduct {
  return {
    id, name: `Produto ${id}`, slug: `produto-${id}`, url_path: `/vinis/produto-${id}`, short_description: null, description_html: null,
    specifications: [], sale_unit, sale_unit_locked: false, brand_id: 1, primary_category_id: 1, category_ids: [1],
    min_quantity: 1, max_quantity: null, quantity_step: 1, min_billable_area_m2: null, fixed_width_m: null, min_width_m: null,
    max_width_m: null, min_height_m: null, max_height_m: null, meta_title: null, meta_description: null, is_active: true,
    is_featured: false, pickup_only: false, activation_issues: [], variants: [makeVariant(id * 10)], images: [], ...ts, deleted_at: null, ...over,
  };
}

export function seedProducts(): AdminProduct[] {
  return [
    makeProduct(1, 'LINEAR_METER', {
      name: 'Vinil Adesivo Branco 1,22 m', slug: 'vinil-adesivo-branco-122m', url_path: '/vinis/vinil-adesivo-branco-122m',
      short_description: 'Vinil branco brilho para plotter de recorte.', min_quantity: 1, quantity_step: 0.1, fixed_width_m: 1.22,
      specifications: [{ label: 'Largura', value: '1,22 m' }, { label: 'Espessura', value: '80 micras' }],
      variants: [makeVariant(10, { sku: 'VIN-BR-122-BR', name: 'Brilho', attributes: { acabamento: 'Brilho' }, has_orders: true })],
      images: [{ id: 1, urls: null, alt: 'Rolo de vinil branco', width: null, height: null, position: 0, variant_id: null, original_filename: 'vinil.jpg', created_at: T0 }],
      sale_unit_locked: true,
    }),
    makeProduct(2, 'SQUARE_METER', {
      name: 'Lona Frontlight 440 g', slug: 'lona-frontlight-440g', url_path: '/lonas/lona-frontlight-440g', primary_category_id: 2, category_ids: [2], brand_id: 2,
      min_billable_area_m2: 0.5, min_width_m: 0.3, max_width_m: 3.2, min_height_m: 0.3, max_height_m: 50,
      variants: [makeVariant(20, { sku: 'LON-FL-440', price_cents: 3000, weight_grams: 440, inventory: { on_hand: 20, reserved: 12, available: 8, low_stock_threshold: 50, is_low_stock: true } })],
    }),
    makeProduct(3, 'BOX', {
      name: 'Ilhós nº 0 latão', slug: 'ilhos-n0', url_path: '/acessorios/ilhos-n0', primary_category_id: 3, category_ids: [3], brand_id: null,
      variants: [makeVariant(30, { sku: 'ILH-0-CX', price_cents: 4500, weight_grams: 800, units_per_box: 1000, inventory: { on_hand: 2, reserved: 0, available: 2, low_stock_threshold: 5, is_low_stock: true } })],
    }),
  ];
}

export function seedMovements(): InventoryMovement[] {
  return [
    { id: 1, variant_id: 10, type: 'in', quantity: 500, on_hand_delta: 500, reserved_delta: 0, on_hand_after: 500, reserved_after: 0, reason: 'Estoque inicial', reference: null, actor: { type: 'admin', id: 1, name: 'Carla Gerente' }, created_at: '2026-09-01T12:00:00Z' },
    { id: 2, variant_id: 10, type: 'out', quantity: 50, on_hand_delta: -50, reserved_delta: 0, on_hand_after: 450, reserved_after: 0, reason: null, reference: { type: 'order', id: 1, label: 'CV-000001' }, actor: { type: 'system', id: null, name: null }, created_at: '2026-09-10T12:00:00Z' },
    { id: 3, variant_id: 10, type: 'reserve', quantity: 30, on_hand_delta: 0, reserved_delta: 30, on_hand_after: 450, reserved_after: 30, reason: null, reference: { type: 'order', id: 3, label: 'CV-000003' }, actor: { type: 'customer', id: 1, name: 'Maria da Silva' }, created_at: '2026-09-24T12:00:00Z' },
  ];
}

function item(id: number, over: Partial<AdminOrderItem> = {}): AdminOrderItem {
  return {
    id, variant_id: 10, product_id: 1, product_name: 'Vinil Adesivo Branco 1,22 m', variant_name: 'Brilho', sku: 'VIN-BR-122-BR', product_url_path: '/vinis/vinil-adesivo-branco-122m',
    sale_unit: 'LINEAR_METER', sale_unit_abbr: 'm', configuration: { quantity: 5, width_m: null, height_m: null, pieces: null }, configuration_label: '5 m',
    billable_quantity: 5, stock_quantity: 5, area_m2: null, min_area_applied: false, unit_price_cents: 1590, base_unit_price_cents: 1590, price_source: 'base',
    subtotal_cents: 7950, discount_cents: 0, total_cents: 7950, weight_grams: 1250, picking_instruction: 'Separar: 5 m', ...over,
  };
}

export function makeOrder(id: number, status: OrderStatus, over: Partial<AdminOrder> = {}, pickup = false): AdminOrder {
  const number = `CV-${String(id).padStart(6, '0')}`;
  return {
    id, uuid: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`, number, status, status_label: status,
    payment_status: status === 'pending_payment' ? 'pending' : status === 'cancelled' ? 'expired' : 'approved', payment_method: 'pix',
    placed_at: '2026-09-24T13:00:00Z', expires_at: status === 'pending_payment' ? '2026-09-24T13:30:00Z' : null,
    paid_at: status === 'pending_payment' ? null : '2026-09-24T13:05:02Z', processing_at: null, shipped_at: null, ready_for_pickup_at: null,
    delivered_at: null, picked_up_at: null, cancelled_at: null, refunded_at: null, cancel_reason_code: null, cancel_reason: null, cancellation_request: null,
    customer: { id: 1, uuid: '11111111-1111-4111-8111-111111111111', type: 'company', name: 'Ana Souza', email: 'ana@grafica.test', phone: '47999990000', document_masked: '**.345.678/0001-**', company_name: 'Gráfica Azul Ltda', state_registration: '123456789' },
    items: [
      item(1),
      item(2, {
        variant_id: 20, product_id: 2, product_name: 'Lona Frontlight 440 g', variant_name: 'Padrão', sku: 'LON-FL-440', sale_unit: 'SQUARE_METER', sale_unit_abbr: 'm²',
        configuration: { quantity: null, width_m: 1.2, height_m: 2.5, pieces: 1 }, configuration_label: '1,20 m × 2,50 m × 1 peça', billable_quantity: 3, stock_quantity: 3,
        area_m2: 3, unit_price_cents: 3000, base_unit_price_cents: 3000, subtotal_cents: 9000, total_cents: 9000, weight_grams: 1320, picking_instruction: 'Cortar: 1 peça de 1,20 × 2,50 m',
      }),
    ],
    totals: { subtotal_cents: 16950, discount_cents: 0, shipping_cents: 2000, shipping_discount_cents: 0, total_cents: 18950 },
    coupon: null, total_weight_grams: 2570, total_volume_cm3: 12500,
    shipping: {
      method_name: pickup ? 'Retirada na loja' : 'Entrega própria', method_type: pickup ? 'pickup' : 'own_delivery', carrier_code: null, service_code: null,
      delivery_days_min: 1, delivery_days_max: 1, delivery_label: '1 dia útil', estimated_delivery_date: '2026-09-25', tracking_code: null, tracking_url: null,
      address: pickup ? null : { recipient_name: 'Ana Souza', phone: '47999990000', postal_code: '89010000', street: 'Rua das Flores', number: '123', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', reference: null, formatted: 'Rua das Flores, 123 – Centro – Blumenau/SC – 89010-000' },
      pickup_address: null, picked_up_at: null, picked_up_by_name: null, method_id: pickup ? 1 : 2, rule_id: 2, option_id: '2:2', quote_uuid: null, picked_up_by_document_masked: null,
    },
    payments: [
      {
        id, uuid: `22222222-2222-4222-8222-${String(id).padStart(12, '0')}`, provider: 'sandbox', method: 'pix', status: status === 'pending_payment' ? 'pending' : 'approved',
        amount_cents: 18950, refunded_cents: 0, external_id: `SBX-${id}`, expires_at: null, paid_at: status === 'pending_payment' ? null : '2026-09-24T13:05:02Z',
        failed_at: null, refunded_at: null, failure_reason: null, refund: null,
        transactions: [{ id: 1, type: 'create', status_before: null, status_after: 'pending', amount_cents: 18950, external_id: null, admin_user: null, created_at: '2026-09-24T13:00:00Z' }],
        created_at: '2026-09-24T13:00:00Z',
      },
    ],
    status_history: [
      { id: 1, from_status: null, to_status: 'pending_payment', actor: { type: 'customer', id: 1, name: 'Ana Souza' }, note: null, created_at: '2026-09-24T13:00:00Z' },
    ],
    notes: 'Entregar após 14h', internal_notes: null, allowed_transitions: [], can_cancel: false, cancel_requires_refund: false, flags: { amount_mismatch: false },
    created_at: '2026-09-24T13:00:00Z', updated_at: '2026-09-24T13:05:02Z', ...over,
  };
}

export function seedOrders(): AdminOrder[] {
  return [
    makeOrder(1, 'paid'),
    makeOrder(2, 'processing'),
    makeOrder(3, 'pending_payment'),
    makeOrder(4, 'ready_for_pickup', {}, true),
    makeOrder(5, 'shipped'),
    makeOrder(6, 'cancelled', { cancel_reason_code: 'payment_expired', cancelled_at: '2026-09-24T14:00:00Z' }),
  ];
}

export function makeCompany(id: number, over: Partial<AdminCompany> = {}): AdminCompany {
  return { id, legal_name: 'Gráfica Azul Ltda', trade_name: 'Gráfica Azul', cnpj_masked: '**.345.678/0001-**', state_registration: '123456789', state_registration_exempt: false, price_list: null, customers: [{ id: 1, name: 'Ana Souza', email: 'ana@grafica.test' }], ...ts, ...over };
}

export function seedCustomers(): AdminCustomer[] {
  const base = {
    email_verified_at: T0, phone: '47999990000', is_active: true, marketing_opt_in: false, terms_version: '2026-09', terms_accepted_at: T0,
    last_login_at: T0, anonymized_at: null, ...ts, deleted_at: null,
  };
  return [
    { ...base, id: 1, uuid: '11111111-1111-4111-8111-111111111111', type: 'company', name: 'Ana Souza', email: 'ana@grafica.test', cpf_masked: null, company: makeCompany(1), price_list: null, effective_price_list: { id: 2, code: 'atacado', name: 'Atacado' }, stats: { orders_count: 5, paid_orders_count: 4, total_spent_cents: 75800, last_order_at: '2026-09-24T13:00:00Z' } },
    { ...base, id: 2, uuid: '33333333-3333-4333-8333-333333333333', type: 'individual', name: 'Maria da Silva', email: 'maria@exemplo.test', cpf_masked: '***.456.789-**', company: null, price_list: null, effective_price_list: null, stats: { orders_count: 1, paid_orders_count: 1, total_spent_cents: 7950, last_order_at: '2026-09-20T13:00:00Z' } },
  ];
}

export function seedPriceLists(): PriceList[] {
  return [
    { id: 1, code: 'varejo', name: 'Varejo', kind: 'retail', discount_bp: null, is_default: true, is_active: true, customers_count: 0, companies_count: 0, tiers_count: 0, ...ts },
    { id: 2, code: 'atacado', name: 'Atacado', kind: 'wholesale', discount_bp: 1000, is_default: false, is_active: true, customers_count: 0, companies_count: 1, tiers_count: 2, ...ts },
  ];
}

export function seedTiers(): PriceTier[] {
  return [
    { id: 1, variant_id: 10, price_list_id: null, min_quantity: 10, price_cents: 1490 },
    { id: 2, variant_id: 10, price_list_id: null, min_quantity: 50, price_cents: 1390 },
    { id: 3, variant_id: 10, price_list_id: 2, min_quantity: 1, price_cents: 1400 },
  ];
}

export function seedCustomerPrices(): CustomerPrice[] {
  return [
    {
      id: 1, customer: null, company: { id: 1, legal_name: 'Gráfica Azul Ltda' },
      variant: { id: 10, sku: 'VIN-BR-122-BR', name: 'Brilho', sale_unit: 'LINEAR_METER', price_cents: 1590, is_active: true, product: { id: 1, name: 'Vinil Adesivo Branco 1,22 m' } },
      price_cents: 1350, starts_at: null, ends_at: null, created_by: { id: 1, name: 'Carla Gerente' }, ...ts,
    },
  ];
}

export function seedPromotions(): Promotion[] {
  return [
    {
      id: 1, name: 'Semana do Vinil', description: null, discount_type: 'percent', value: 1500, scope: 'targeted', starts_at: '2026-10-01T03:00:00Z', ends_at: '2026-10-08T02:59:00Z',
      is_active: true, priority: 10, status: 'scheduled', product_ids: [], category_ids: [1], brand_ids: [],
      targets: { products: [], categories: [{ id: 1, name: 'Vinis', slug: 'vinis', url_path: '/vinis' }], brands: [] }, ...ts, deleted_at: null,
    },
  ];
}

export function seedCoupons(): Coupon[] {
  return [
    { id: 1, code: 'BEMVINDO10', description: 'Primeira compra', type: 'percent', value: 1000, min_order_cents: 10000, max_discount_cents: 5000, starts_at: null, ends_at: null, usage_limit: 100, usage_limit_per_customer: 1, times_used: 12, is_active: true, status: 'active', ...ts, deleted_at: null },
    { id: 2, code: 'FRETEGRATIS', description: null, type: 'free_shipping', value: 0, min_order_cents: 20000, max_discount_cents: null, starts_at: null, ends_at: null, usage_limit: null, usage_limit_per_customer: null, times_used: 3, is_active: true, status: 'active', ...ts, deleted_at: null },
  ];
}

export function seedCarriers(): Carrier[] {
  return [{ id: 1, name: 'Transportadora Rápida', code: 'rapida', driver: 'fake', settings: { timeout_ms: 5000 }, has_credentials: true, is_active: true, methods_count: 1, ...ts }];
}

export function seedMethods(): ShippingMethod[] {
  const base = { description: null, handling_days: 0, weight_basis: 'real' as const, cubic_divisor: null, accepts_free_shipping_coupon: true, is_active: true, ...ts, deleted_at: null };
  return [
    { ...base, id: 1, name: 'Retirada na loja', code: 'retirada', type: 'pickup', carrier_id: null, carrier_service_code: null, delivery_days_min: 0, delivery_days_max: 1, position: 0, accepts_free_shipping_coupon: false, rules_count: 0,
      pickup: { street: 'Rua XV de Novembro', number: '1000', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', postal_code: '89010001', instructions: 'Leve documento com foto', opening_hours: 'Seg–Sex 8h–18h' } },
    { ...base, id: 2, name: 'Entrega própria', code: 'entrega-propria', type: 'own_delivery', carrier_id: null, carrier_service_code: null, delivery_days_min: 1, delivery_days_max: 1, position: 1, pickup: null, rules_count: 3 },
    { ...base, id: 3, name: 'Transportadora Rápida', code: 'rapida-expresso', type: 'carrier', carrier_id: 1, carrier_service_code: 'EXP', delivery_days_min: 3, delivery_days_max: 5, position: 2, pickup: null, rules_count: 0 },
  ];
}

export function seedZones(): ShippingZone[] {
  return [
    { id: 1, name: 'Blumenau', description: 'Cidade de Blumenau', is_active: true, postal_ranges: [{ id: 1, start_postal_code: '89000000', end_postal_code: '89099999' }], cities: [{ id: 1, city_ibge_code: '4202404', city_name: 'Blumenau', state: 'SC' }], states: [], rules_count: 3, ...ts },
    { id: 2, name: 'Região', description: 'Gaspar e Pomerode', is_active: true, postal_ranges: [], cities: [{ id: 2, city_ibge_code: '4205902', city_name: 'Gaspar', state: 'SC' }, { id: 3, city_ibge_code: '4213203', city_name: 'Pomerode', state: 'SC' }], states: [], rules_count: 1, ...ts },
  ];
}

export function makeRule(id: number, over: Partial<ShippingRule> = {}): ShippingRule {
  return {
    id, method_id: 2, zone_id: 1, name: `Regra ${id}`, priority: id * 10, min_weight_grams: null, max_weight_grams: null, min_subtotal_cents: null, max_subtotal_cents: null,
    min_volume_cm3: null, max_volume_cm3: null, max_package_length_cm: null, price_type: 'fixed', price_cents: 2000, per_kg_cents: 0, percentage_bp: 0,
    min_price_cents: null, max_price_cents: null, delivery_days_min: 1, delivery_days_max: 1, valid_from: null, valid_until: null, is_active: true,
    summary: '', ...ts, deleted_at: null, ...over,
  };
}

export function seedRules(): ShippingRule[] {
  return [
    makeRule(1, { name: 'Grátis acima de R$ 500', min_subtotal_cents: 50000, price_type: 'free', price_cents: 0, summary: 'Blumenau · Subtotal ≥ R$ 500,00 → Grátis' }),
    makeRule(2, { name: 'Blumenau até 10 kg', max_weight_grams: 10000, summary: 'Blumenau · ≤ 10 kg → R$ 20,00' }),
    makeRule(3, { name: 'Blumenau 10–50 kg', min_weight_grams: 10001, max_weight_grams: 50000, price_cents: 3500, summary: 'Blumenau · 10–50 kg → R$ 35,00' }),
    makeRule(4, { zone_id: 2, priority: 10, name: 'Região até 30 kg', max_weight_grams: 30000, price_cents: 4000, delivery_days_min: 2, delivery_days_max: 2, summary: 'Região · ≤ 30 kg → R$ 40,00' }),
  ];
}

export function seedRoles(): Role[] {
  return [
    { id: 1, name: 'super-admin', label: 'Super Admin', is_system: true, permissions: [...ALL_PERMISSIONS], users_count: 1 },
    { id: 2, name: 'manager', label: 'Gerente', is_system: true, permissions: ALL_PERMISSIONS.filter((p) => p !== 'admin_users.manage'), users_count: 1 },
    { id: 3, name: 'warehouse', label: 'Estoque/Expedição', is_system: true, permissions: ['dashboard.view', 'products.view', 'inventory.view', 'inventory.move', 'inventory.adjust', 'orders.view', 'orders.fulfill', 'orders.pickup', 'orders.notes', 'reports.inventory'], users_count: 1 },
  ];
}

export function seedUsers(): AdminUser[] {
  return [makeAdminUser(), makeAdminUser({ id: 2, name: 'Carlos (Expedição)', email: 'carlos@comunika.test', roles: ['warehouse'] })];
}

export function seedSettings(): Setting[] {
  const s = (key: Setting['key'], value: unknown, type: Setting['type'], group: Setting['group'], description: string | null = null): Setting => ({ key, value, type, group, is_public: false, description, updated_at: T0, updated_by: null });
  return [
    s('store.name', 'Comunika Suprimentos', 'string', 'store'),
    s('store.legal_name', 'Comunika Suprimentos Ltda', 'string', 'store'),
    s('store.document', '12345678000190', 'string', 'store'),
    s('store.phone', '4733330000', 'string', 'store'),
    s('store.whatsapp', '47999990000', 'string', 'store'),
    s('store.email', 'contato@comunika.test', 'string', 'store'),
    s('store.opening_hours', 'Seg–Sex 8h–18h', 'string', 'store'),
    s('store.address', { street: 'Rua XV de Novembro', number: '1000', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', postal_code: '89010001', city_ibge_code: '4202404' }, 'object', 'store'),
    s('store.social_links', { instagram: 'https://instagram.com/comunika', facebook: null, youtube: null }, 'object', 'store'),
    s('orders.number_prefix', 'CV-', 'string', 'checkout'),
    s('checkout.pix_expiry_minutes', 30, 'integer', 'checkout', 'Tempo para pagar o PIX'),
    s('checkout.min_order_cents', 0, 'integer', 'checkout'),
    s('cart.guest_ttl_days', 30, 'integer', 'checkout'),
    s('shipping.quote_ttl_minutes', 30, 'integer', 'shipping'),
    s('shipping.origin_postal_code', '89010001', 'string', 'shipping'),
    s('inventory.default_low_stock_threshold', 10, 'decimal', 'inventory'),
    s('inventory.show_low_stock_quantity', true, 'boolean', 'inventory'),
    s('legal.terms_version', '2026-09', 'string', 'legal'),
    s('storefront.free_shipping_banner', { enabled: true, threshold_cents: 50000, text: 'Frete grátis em Blumenau acima de R$ 500' }, 'object', 'storefront'),
    s('notifications.whatsapp_enabled', false, 'boolean', 'notifications'),
    s('notifications.admin_alert_emails', ['financeiro@comunika.test'], 'string_list', 'notifications'),
    s('content.about', 'Somos a Comunika.', 'text', 'content'),
    s('content.terms', 'Termos de uso…', 'text', 'content'),
    s('content.privacy', 'Política de privacidade…', 'text', 'content'),
    s('content.returns', 'Trocas e devoluções…', 'text', 'content'),
  ];
}

export function seedAudit(): AuditLog[] {
  return [
    { id: 1, actor: { type: 'admin', id: 1, name: 'Carla Gerente' }, action: 'product.updated', auditable_type: 'product', auditable_id: 1, auditable_label: 'Vinil Adesivo Branco 1,22 m', old_values: { name: 'Vinil Branco', is_featured: false }, new_values: { name: 'Vinil Adesivo Branco 1,22 m', is_featured: true }, ip: '127.0.0.1', user_agent: 'Mozilla', request_id: '01J8ZZ', created_at: '2026-09-24T15:00:00Z' },
    { id: 2, actor: { type: 'system', id: null, name: null }, action: 'order.status_changed', auditable_type: 'order', auditable_id: 1, auditable_label: 'CV-000001', old_values: { status: 'pending_payment' }, new_values: { status: 'paid' }, ip: null, user_agent: null, request_id: null, created_at: '2026-09-24T13:05:02Z' },
    { id: 3, actor: { type: 'admin', id: 1, name: 'Carla Gerente' }, action: 'customer.updated', auditable_type: 'customer', auditable_id: 2, auditable_label: 'Maria da Silva', old_values: { cpf: '••••' }, new_values: { cpf: '••••' }, ip: '127.0.0.1', user_agent: null, request_id: '01J900', created_at: '2026-09-23T10:00:00Z' },
  ];
}
