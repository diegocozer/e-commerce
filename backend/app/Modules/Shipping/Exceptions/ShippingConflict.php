<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use App\Modules\Shipping\DTOs\ShippingQuoteData;
use App\Shared\Exceptions\DomainException;

/**
 * 409 shipping_* at checkout (SHIPPING.md §7, API.md §1.6). Body:
 * {message, code, shipping_quote: ShippingQuote}. `newQuote` is the fresh quote.
 */
final class ShippingConflict extends DomainException
{
    public const array MESSAGES = [
        'shipping_quote_invalid' => 'Cotação de frete inválida. Escolha novamente a forma de entrega.',
        'shipping_quote_expired' => 'A cotação de frete expirou. Escolha novamente a forma de entrega.',
        'shipping_quote_changed' => 'Seu carrinho mudou desde a cotação de frete. Escolha novamente a forma de entrega.',
        'shipping_postal_code_changed' => 'O CEP de entrega mudou. Escolha novamente a forma de entrega.',
        'shipping_option_invalid' => 'Forma de entrega inválida. Escolha novamente a forma de entrega.',
        'shipping_price_changed' => 'O valor do frete foi atualizado. Escolha novamente a forma de entrega.',
        'shipping_option_unavailable' => 'A forma de entrega escolhida não está mais disponível. Escolha outra opção.',
    ];

    public function __construct(public readonly string $reason, public readonly ShippingQuoteData $newQuote)
    {
        parent::__construct(self::MESSAGES[$reason] ?? self::MESSAGES['shipping_price_changed'], $reason, 409, [
            'shipping_quote' => $newQuote->toArray(),
        ]);
    }
}
