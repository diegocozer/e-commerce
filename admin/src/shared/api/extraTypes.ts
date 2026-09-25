/**
 * Tipos do painel descritos em prosa na API.md (e não em blocos `ts`) ou em SHIPPING.md §10.1.
 */
import type {
  AdminProductImage,
  Cents,
  Grams,
  ISODateTime,
  OrderStatus,
  ShippingOption,
  ShippingRule,
  ShippingZone,
  UF,
} from './types';

export type BulkProductAction = 'activate' | 'deactivate' | 'delete' | 'set_primary_category';
export interface BulkResult {
  succeeded: number[];
  failed: { id: number; errors: string[] }[];
}

export type OrderStatusCounts = Record<OrderStatus, number> & { all: number; cancellation_requests: number };

export interface ResourceInUseBlocker {
  type: string;
  id: number;
  label: string;
}

export interface CarrierDriver {
  driver: string;
  name: string;
  settings_schema: Record<string, 'string' | 'integer' | 'boolean'>;
}

export interface ZoneSaveResponse {
  data: ShippingZone;
  warnings: string[];
}
export type RuleWarning = 'tie_broken_by_id' | 'free_rule_without_coverage_limit' | 'rule_never_reachable';
export interface RuleSaveResponse {
  data: ShippingRule;
  warnings: RuleWarning[];
}

export interface ZoneTestResult {
  matches: boolean;
  matched_by: string | null;
  destination: { city: string | null; state: UF | null; city_ibge_code: string | null; resolved: boolean };
}

/** SHIPPING.md §10.1 — resultado completo do simulador (com trace). */
export type RuleTraceResult = 'matched' | 'rejected' | 'not_evaluated' | 'zone_not_matched' | 'inactive' | string;
export interface RuleTrace {
  rule_id: number;
  priority?: number;
  specificity?: string;
  result: RuleTraceResult;
  reasons?: { code: string; detail: string | null }[];
}
export interface MethodTrace {
  method_id: number;
  code: string;
  name?: string;
  status: 'option' | 'unavailable' | 'skipped' | string;
  reason?: string;
  detail?: string | null;
  coverage?: { covered: boolean; via_zones: number[] };
  effective_weight_grams?: Grams;
  weight_basis?: string;
  rules?: RuleTrace[];
  winner_rule_id?: number | null;
  price_breakdown?: Record<string, number | string | null>;
  duration_ms?: number;
  warnings?: string[];
}
export interface SimulationResult {
  destination: {
    postal_code: string;
    city: string | null;
    city_ibge_code: string | null;
    state: UF | null;
    resolved: boolean;
    state_source?: string;
  };
  logistics: {
    total_weight_grams: Grams;
    total_volume_cm3: number;
    cubic_weight_grams_6000?: number;
    volumes_count?: number;
    largest_dimension_cm: number;
    has_pickup_only_items?: boolean;
    missing_data?: boolean;
  };
  zones_matched: { zone_id: number; name: string; matched_by: string; specificity: string }[];
  methods: MethodTrace[];
  options: ShippingOption[];
  unavailable: { method_id: number; method_code: string; reason: string; detail: string | null }[];
}
export interface SimulationRequest {
  postal_code: string;
  items?: { variant_id: number; quantity?: number; width_m?: number; height_m?: number; pieces?: number }[];
  order_id?: number;
  logistics_override?: { total_weight_grams: Grams; total_volume_cm3: number; largest_dimension_cm: number };
  subtotal_cents?: Cents;
  coupon_free_shipping?: boolean;
  at?: ISODateTime;
  method_ids?: number[];
}

export interface ImageUploadState {
  key: string;
  file: File;
  progress: 'uploading' | 'error' | 'done';
  error?: string;
  image?: AdminProductImage;
}

export interface FailedJob {
  uuid: string;
  queue: string;
  job: string;
  exception_summary: string;
  failed_at: ISODateTime;
}
