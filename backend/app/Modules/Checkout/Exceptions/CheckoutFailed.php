<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Exceptions;

use App\Shared\Exceptions\DomainException;

/**
 * Checkout errors of API.md §1.6/§3.E rendered as {message, code, ...details}:
 * cart_empty, cart_invalid (422), price_changed, idempotency_conflict, and
 * 503 payment_gateway_unavailable (order already created).
 */
final class CheckoutFailed extends DomainException
{
    /** @param array<string, mixed> $details */
    public static function make(string $code, string $message, array $details = [], int $status = 409): self
    {
        return new self($message, $code, $status, $details);
    }

    public static function cartEmpty(): self
    {
        return self::make('cart_empty', 'Seu carrinho está vazio.');
    }

    /** @param list<array<string, mixed>> $items CartItemIssue[] */
    public static function cartInvalid(array $items): self
    {
        $message = 'Há itens indisponíveis ou inválidos no carrinho. Revise o carrinho para continuar.';

        return self::make('cart_invalid', $message, ['errors' => ['cart' => [$message]], 'items' => $items], 422);
    }

    /** @param array<string, mixed> $summary CheckoutSummary */
    public static function priceChanged(array $summary): self
    {
        return self::make('price_changed', 'Os valores do pedido mudaram. Revise e confirme novamente.', ['summary' => $summary]);
    }

    public static function idempotencyConflict(string $uuid, string $number): self
    {
        return self::make('idempotency_conflict', 'Esta chave de idempotência já foi usada com outros dados.',
            ['order' => ['uuid' => $uuid, 'number' => $number]]);
    }

    public static function gatewayUnavailable(string $uuid, string $number): self
    {
        return self::make('payment_gateway_unavailable', 'Não foi possível gerar o PIX agora. Seu pedido foi criado; tente novamente.',
            ['order' => ['uuid' => $uuid, 'number' => $number]], 503);
    }
}
