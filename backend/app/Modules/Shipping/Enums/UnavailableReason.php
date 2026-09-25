<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** Why a method produced no option (SHIPPING.md §3). Never exposed publicly (except pickup_only_items as `notice`). */
enum UnavailableReason: string
{
    case DestinationUnresolved = 'destination_unresolved';
    case OutOfCoverage = 'out_of_coverage';
    case NoRuleMatched = 'no_rule_matched';
    case WeightAboveLimit = 'weight_above_limit';
    case VolumeAboveLimit = 'volume_above_limit';
    case LogisticsDataMissing = 'logistics_data_missing';
    case PickupOnlyItems = 'pickup_only_items';
    case CarrierNotRegistered = 'carrier_not_registered';
    case CarrierInactive = 'carrier_inactive';
    case CarrierUnsupported = 'carrier_unsupported';
    case CarrierTimeout = 'carrier_timeout';
    case CarrierError = 'carrier_error';
    case CarrierServiceMissing = 'carrier_service_missing';
    case CarrierBudgetExceeded = 'carrier_budget_exceeded';
}
