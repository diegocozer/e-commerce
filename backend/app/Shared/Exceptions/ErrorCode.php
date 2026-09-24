<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/** Minimum machine error codes of ADR-020 (responses other than 422). */
enum ErrorCode: string
{
    case InsufficientStock = 'insufficient_stock';
    case PriceChanged = 'price_changed';
    case ShippingQuoteExpired = 'shipping_quote_expired';
    case ShippingOptionUnavailable = 'shipping_option_unavailable';
    case CouponInvalid = 'coupon_invalid';
    case IdempotencyConflict = 'idempotency_conflict';
    case PaymentGatewayUnavailable = 'payment_gateway_unavailable';
    case InvalidStatusTransition = 'invalid_status_transition';
    case CartEmpty = 'cart_empty';
    case TooManyPendingOrders = 'too_many_pending_orders';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case Unauthenticated = 'unauthenticated';
    case TooManyRequests = 'too_many_requests';
    case AdminSessionExpired = 'admin_session_expired';
    case CsrfTokenMismatch = 'csrf_token_mismatch';
    case MethodNotAllowed = 'method_not_allowed';
    case BadRequest = 'bad_request';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case ServiceUnavailable = 'service_unavailable';
    case ServerError = 'server_error';
    case HttpError = 'http_error';

    public static function forStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            413 => self::PayloadTooLarge,
            419 => self::CsrfTokenMismatch,
            429 => self::TooManyRequests,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::ServerError : self::HttpError,
        };
    }
}
