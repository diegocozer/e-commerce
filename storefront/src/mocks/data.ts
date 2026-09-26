// Fixtures espelhando os exemplos de docs/API.md §4 (seed DATABASE §7). Usadas pelos
// handlers MSW (VITE_USE_MOCKS=true) e pelos testes.
import type {
  Address,
  CategoryNode,
  Customer,
  PriceTierDisplay,
  ProductCard,
  ProductDetail,
  ProductImage,
  ProductVariant,
  PublicSettings,
  SaleUnit,
  SaleUnitRules,
  ShippingOption,
} from '@/shared/api/types';

export const ORIGIN = 'https://loja.exemplo.com.br';

export const settings: PublicSettings = {
  store: {
    name: 'Comunika Suprimentos',
    phone: '4733330000',
    whatsapp: '47999990000',
    email: 'contato@comunika.exemplo.com.br',
    address: { street: 'Rua XV de Novembro', number: '1000', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', postal_code: '89010001' },
    opening_hours: 'Seg–Sex 8h–18h, Sáb 8h–12h',
    social_links: { instagram: null, facebook: null, youtube: null },
  },
  pickup_points: [
    { method_code: 'pickup-store', name: 'Retirada na empresa', street: 'Rua XV de Novembro', number: '1000', complement: null, district: 'Centro', city: 'Blumenau', state: 'SC', postal_code: '89010001', opening_hours: 'Seg–Sex 8h–18h', instructions: 'Apresente o número do pedido.' },
  ],
  free_shipping_banner: { enabled: true, threshold_cents: 50000, text: 'Frete grátis na região de Blumenau acima de R$ 500' },
  terms_version: '2026-09',
  checkout: { payment_methods: ['pix'], pix_expiry_minutes: 30, min_order_cents: 0 },
  features: { show_low_stock_quantity: true },
};

const cat = (id: number, name: string, slug: string, position: number, children: CategoryNode[] = []): CategoryNode => ({ id, name, slug, url_path: `/${slug}`, image_url: null, position, children });

export const categoryTree: CategoryNode[] = [
  cat(1, 'Lonas', 'lonas', 0),
  cat(2, 'Vinis', 'vinis', 1, [cat(11, 'Vinil Adesivo', 'vinil-adesivo', 0)]),
  cat(3, 'Adesivos', 'adesivos', 2),
  cat(4, 'Papéis', 'papeis', 3),
  cat(5, 'Bobinas', 'bobinas', 4),
  cat(6, 'Tintas', 'tintas', 5),
  cat(8, 'Ilhós', 'ilhoses', 6),
  cat(9, 'Ferramentas', 'ferramentas', 7),
  cat(10, 'Acessórios', 'acessorios', 8),
];

export function flatCategories(): CategoryNode[] {
  return categoryTree.flatMap((c) => [c, ...c.children]);
}

function rules(sale_unit: SaleUnit, over: Partial<SaleUnitRules> = {}): SaleUnitRules {
  const input = sale_unit === 'SQUARE_METER' ? 'dimensions' : sale_unit === 'LINEAR_METER' || sale_unit === 'KG' ? 'decimal' : 'integer';
  return {
    sale_unit, input, min_quantity: 1, max_quantity: null, quantity_step: 1,
    fixed_width_m: null, min_width_m: null, max_width_m: null, min_height_m: null, max_height_m: null,
    min_billable_area_m2: null, dimension_decimals: 2, max_pieces: 1000, ...over,
  };
}

function img(id: number, productId: number, alt: string): ProductImage {
  // SVG embutido (sem rede) — placeholder visual dos mocks.
  const svg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%23E8F1FA'/><text x='50' y='56' font-size='12' text-anchor='middle' fill='%230B4F8A' font-family='Arial'>${productId}</text></svg>`;
  const url = `data:image/svg+xml;utf8,${svg}`;
  return { id, urls: { w300: url, w800: url, w1600: url }, alt, width: 1600, height: 1600, position: 0, variant_id: null };
}

interface VariantSeed {
  id: number;
  sku: string;
  name: string;
  attributes?: Record<string, string>;
  price: number;
  tiers?: [number, number][]; // [min_quantity, price]
  weight: number;
  stock: number; // disponível (unidade de estoque)
  rules?: Partial<SaleUnitRules>;
  roll_length_m?: number;
  units_per_box?: number;
}

interface ProductSeed {
  id: number;
  slug: string;
  name: string;
  sale_unit: SaleUnit;
  category: number;
  brand?: { id: number; name: string; slug: string };
  short?: string;
  description?: string;
  specs?: [string, string][];
  axes?: { key: string; label: string; values: string[] }[];
  variants: VariantSeed[];
  featured?: boolean;
  pickup_only?: boolean;
  best?: number;
  promo?: boolean;
}

const LABEL: Record<SaleUnit, [string, string]> = {
  UNIT: ['Unidade', 'un'], LINEAR_METER: ['Metro linear', 'm'], SQUARE_METER: ['Metro quadrado', 'm²'], ROLL: ['Rolo', 'rolo'], KG: ['Quilograma', 'kg'], BOX: ['Caixa', 'cx'],
};

export const stock = new Map<number, number>();

export const productSeeds: ProductSeed[] = [
  {
    id: 1, slug: 'vinil-adesivo-branco-122m', name: 'Vinil Adesivo Branco', sale_unit: 'LINEAR_METER', category: 2,
    brand: { id: 2, name: 'VinilSul', slug: 'vinilsul' }, featured: true, best: 90,
    short: 'Vinil adesivo branco 1,22 m para plotter de recorte e impressão.',
    description: '<p>Vinil adesivo branco com adesivo permanente.</p><ul><li>Largura 1,22 m</li></ul><script>alert(1)</script>',
    specs: [['Largura útil', '1,22 m'], ['Adesivo', 'Permanente']],
    axes: [{ key: 'acabamento', label: 'Acabamento', values: ['Brilho', 'Fosco'] }],
    variants: [
      { id: 1, sku: 'VIN-BR-122-BR', name: 'Brilho', attributes: { acabamento: 'Brilho' }, price: 1590, tiers: [[1, 1590], [10, 1490], [50, 1390]], weight: 250, stock: 500, rules: { min_quantity: 1, max_quantity: 50, quantity_step: 0.1, fixed_width_m: 1.22 } },
      { id: 2, sku: 'VIN-BR-122-FO', name: 'Fosco', attributes: { acabamento: 'Fosco' }, price: 1690, weight: 250, stock: 300, rules: { min_quantity: 1, max_quantity: 50, quantity_step: 0.1, fixed_width_m: 1.22 } },
    ],
  },
  {
    id: 2, slug: 'lona-frontlight-440g', name: 'Lona Frontlight 440 g', sale_unit: 'SQUARE_METER', category: 1,
    brand: { id: 3, name: 'Imprimax', slug: 'imprimax' }, featured: true, best: 80,
    short: 'Lona frontlight 440 g/m² sob medida.',
    description: '<p>Lona para impressão digital, cortada sob medida.</p>',
    specs: [['Gramatura', '440 g/m²'], ['Acabamento', 'Fosco']],
    variants: [{ id: 7, sku: 'LON-FL-440-SM', name: 'Sob medida', price: 3000, tiers: [[0.001, 3000], [20, 2700]], weight: 460, stock: 400, rules: { min_quantity: 1, quantity_step: 1, min_width_m: 0.3, max_width_m: 3.2, min_height_m: 0.3, max_height_m: 50, min_billable_area_m2: 1 } }],
  },
  {
    id: 3, slug: 'lona-backlight-320m', name: 'Lona Backlight 3,20 m', sale_unit: 'SQUARE_METER', category: 1,
    brand: { id: 3, name: 'Imprimax', slug: 'imprimax' }, best: 40, promo: true,
    variants: [{ id: 8, sku: 'LON-BL-320', name: 'Padrão', price: 3200, weight: 500, stock: 25, rules: { min_quantity: 1, quantity_step: 1, fixed_width_m: 3.2, min_height_m: 0.5, max_height_m: 50, min_billable_area_m2: 0.5 } }],
  },
  {
    id: 4, slug: 'ilhos-n0-latao', name: 'Ilhós nº 0 latão', sale_unit: 'UNIT', category: 8, best: 70,
    variants: [{ id: 10, sku: 'ILH-0-LAT', name: 'Padrão', price: 50, weight: 2, stock: 5000, rules: { min_quantity: 1, quantity_step: 1 } }],
  },
  {
    id: 5, slug: 'bobina-sulfite-90g', name: 'Bobina Papel Sulfite 90 g 0,914 × 50 m', sale_unit: 'ROLL', category: 5, best: 30,
    variants: [{ id: 11, sku: 'BOB-SUL-90', name: 'Padrão', price: 35000, weight: 5000, stock: 3, roll_length_m: 50 }],
  },
  {
    id: 6, slug: 'lamina-estilete-18mm', name: 'Lâmina de estilete 18 mm', sale_unit: 'BOX', category: 9, best: 20, promo: true,
    variants: [{ id: 12, sku: 'LAM-18-CX10', name: 'Caixa c/ 10', price: 2490, weight: 100, stock: 80, units_per_box: 10 }],
  },
  {
    id: 7, slug: 'po-hot-melt-dtf', name: 'Pó Hot Melt DTF', sale_unit: 'KG', category: 6, best: 10,
    variants: [{ id: 13, sku: 'DTF-PO-KG', name: 'Padrão', price: 8990, weight: 1000, stock: 40, rules: { min_quantity: 0.5, quantity_step: 0.5 } }],
  },
  {
    id: 8, slug: 'chapa-acm-3m', name: 'Chapa ACM 3 m', sale_unit: 'UNIT', category: 10, pickup_only: true, best: 5,
    variants: [{ id: 14, sku: 'ACM-3M-BR', name: 'Branca', price: 45000, weight: 9000, stock: 0 }],
  },
];

for (const p of productSeeds) for (const v of p.variants) stock.set(v.id, v.stock);

function tiersOf(v: VariantSeed): PriceTierDisplay[] {
  if (!v.tiers) return [];
  return v.tiers.map(([min, price], i) => {
    const next = v.tiers![i + 1];
    const stepM = v.rules?.quantity_step ?? 1;
    const max = next ? Math.round((next[0] - (stepM < 1 ? stepM : 0.001)) * 1000) / 1000 : null;
    return { min_quantity: min, max_quantity: max, unit_price_cents: price, price_source: i === 0 ? 'base' : 'tier' };
  });
}

function availability(v: VariantSeed, r: SaleUnitRules) {
  const s = stock.get(v.id) ?? 0;
  if (s < r.min_quantity) return { status: 'out_of_stock' as const, available_quantity: null };
  if (s <= 30) return { status: 'low_stock' as const, available_quantity: s };
  return { status: 'in_stock' as const, available_quantity: null };
}

export function findSeed(slug: string): ProductSeed | undefined {
  return productSeeds.find((p) => p.slug === slug);
}

export function findVariant(variantId: number): { product: ProductSeed; variant: VariantSeed } | undefined {
  for (const product of productSeeds) {
    const variant = product.variants.find((v) => v.id === variantId);
    if (variant) return { product, variant };
  }
  return undefined;
}

export function variantOf(p: ProductSeed, v: VariantSeed, i: number): ProductVariant {
  const r = rules(p.sale_unit, v.rules);
  const tiers = tiersOf(v);
  return {
    id: v.id, sku: v.sku, gtin: null, name: v.name, attributes: v.attributes ?? {}, position: i, is_default: i === 0, image_ids: [], rules: r,
    price: { unit_price_cents: v.price, base_unit_price_cents: v.price, compare_at_cents: null, price_source: 'base', price_source_label: null, promotion: null, tiers },
    availability: availability(v, r), weight_grams: v.weight, roll_length_m: v.roll_length_m ?? null, units_per_box: v.units_per_box ?? null,
  };
}

export function categoryRef(id: number) {
  const c = flatCategories().find((x) => x.id === id)!;
  return { id: c.id, name: c.name, slug: c.slug, url_path: c.url_path };
}

export function productDetail(p: ProductSeed): ProductDetail {
  const primary = categoryRef(p.category);
  const url_path = `${primary.url_path}/${p.slug}`;
  const image = img(p.id * 10, p.id, `${p.name} — foto 1 de 1`);
  const variants = p.variants.map((v, i) => variantOf(p, v, i));
  return {
    id: p.id, slug: p.slug, name: p.name, url_path, short_description: p.short ?? null, description_html: p.description ?? null,
    specifications: (p.specs ?? []).map(([label, value]) => ({ label, value })),
    sale_unit: p.sale_unit, sale_unit_label: LABEL[p.sale_unit][0], sale_unit_abbr: LABEL[p.sale_unit][1],
    brand: p.brand ?? null, primary_category: primary, categories: [primary],
    breadcrumbs: [{ name: 'Início', url_path: '/' }, { name: primary.name, url_path: primary.url_path }, { name: p.name, url_path: null }],
    images: [image], attribute_axes: p.axes ?? [], variants, default_variant_id: variants[0].id, pickup_only: Boolean(p.pickup_only), is_featured: Boolean(p.featured),
    seo: {
      title: `${p.name} | Comunika Suprimentos`, description: p.short ?? p.name, canonical_path: url_path, canonical_url: `${ORIGIN}${url_path}`,
      robots: 'index,follow', og_image_url: null, json_ld: [],
    },
  };
}

export function productCard(p: ProductSeed): ProductCard {
  const d = productDetail(p);
  const v = d.variants[0];
  const minTier = v.price.tiers.reduce((m, t) => Math.min(m, t.unit_price_cents), v.price.unit_price_cents);
  const keyAttr = p.sale_unit === 'LINEAR_METER' && v.rules.fixed_width_m ? `Largura ${String(v.rules.fixed_width_m).replace('.', ',')} m` : v.roll_length_m ? `Rolo ${v.roll_length_m} m` : v.units_per_box ? `Caixa c/ ${v.units_per_box} un` : null;
  const statuses = d.variants.map((x) => x.availability.status);
  return {
    id: d.id, slug: d.slug, name: d.name, url_path: d.url_path, sale_unit: d.sale_unit, sale_unit_label: d.sale_unit_label, sale_unit_abbr: d.sale_unit_abbr,
    brand: d.brand, primary_category: d.primary_category, image: d.images[0] ?? null, variants_count: d.variants.length,
    default_variant: { id: v.id, sku: v.sku, name: v.name }, key_attribute: keyAttr,
    price: { unit_price_cents: v.price.unit_price_cents, compare_at_cents: null, price_source: 'base', price_source_label: null, from_price_cents: minTier < v.price.unit_price_cents ? minTier : null },
    availability: { status: statuses.includes('in_stock') ? 'in_stock' : statuses.includes('low_stock') ? 'low_stock' : 'out_of_stock' },
    pickup_only: d.pickup_only, is_featured: d.is_featured,
    quick_add: ['UNIT', 'ROLL', 'BOX'].includes(p.sale_unit) && d.variants.length === 1 && v.availability.status !== 'out_of_stock' && v.rules.min_quantity === 1,
  };
}

export const demoCustomer: Customer = {
  uuid: '5b0e5a8e-7c55-4f7e-9a51-0c9f8b2a1e10', type: 'individual', name: 'Maria da Silva', email: 'maria@example.com', email_verified: true,
  phone: '47999990001', cpf: '52998224725', marketing_opt_in: false, company: null, price_list: null,
  terms: { accepted_version: '2026-09', current_version: '2026-09', needs_acceptance: false }, profile_complete: true, missing_fields: [], created_at: '2026-09-01T12:00:00Z',
};
export const DEMO_PASSWORD = 'senha1234';

export const demoAddress: Address = {
  uuid: '7c9e6679-7425-40de-944b-e07fc1f90ae7', label: 'Casa', recipient_name: 'Maria da Silva', phone: '47999990001', postal_code: '89012000',
  street: 'Rua das Palmeiras', number: '123', complement: null, district: 'Victor Konder', city: 'Blumenau', state: 'SC', city_ibge_code: '4202404',
  reference: null, is_default: true, formatted: 'Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000', created_at: '2026-09-01T12:00:00Z',
};

/** Opções de frete do seed (API §4.5) — preço grátis acima de R$ 500 para entrega própria/CEP. */
export function shippingOptions(subtotalCents: number, pickupOnly: boolean): ShippingOption[] {
  const free = subtotalCents >= 50000;
  const pickup: ShippingOption = {
    option_id: '1:pickup', method_code: 'pickup-store', method_type: 'pickup', name: 'Retirada na empresa', description: null, price_cents: 0, original_price_cents: 0,
    is_free: true, free_reason: 'rule', delivery_days_min: 1, delivery_days_max: 1, delivery_label: 'Disponível em 1 dia útil após o pagamento', carrier: null,
    pickup_address: { ...settings.pickup_points[0] },
  };
  const mk = (option_id: string, method_code: string, method_type: ShippingOption['method_type'], name: string, price: number, min: number, max: number, label: string, canFree: boolean): ShippingOption => ({
    option_id, method_code, method_type, name, description: null, price_cents: canFree && free ? 0 : price, original_price_cents: price, is_free: canFree && free,
    free_reason: canFree && free ? 'rule' : null, delivery_days_min: min, delivery_days_max: max, delivery_label: label, carrier: null, pickup_address: null,
  });
  if (pickupOnly) return [pickup];
  return [
    pickup,
    mk('3:6', 'table-regional', 'table_rate', 'Transportadora regional (tabela)', 1500, 2, 4, '2 a 4 dias úteis', false),
    mk('4:11', 'table-cep', 'table_rate', 'Frete por CEP', 1800, 3, 6, '3 a 6 dias úteis', true),
    mk('2:2', 'own-delivery', 'own_delivery', 'Entrega própria', 2000, 1, 1, '1 dia útil', true),
  ].sort((a, b) => a.price_cents - b.price_cents || a.delivery_days_max - b.delivery_days_max);
}

export const PIX_QR_BASE64 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
