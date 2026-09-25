<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 422 for quantity/dimension rules (RN-QTD). Rendered as the standard
 * Laravel body plus `details.<field>.suggestions` when off-step (API.md §1.6).
 * Callers may rename the field (e.g. "items.2.quantity") with withField().
 */
final class InvalidSaleQuantity extends ValidationException
{
    public string $field = 'quantity';

    /** @var array<string, list<string>> */
    private array $messages = [];

    public string $reason = 'invalid';

    /** @var list<int|float>|null */
    public ?array $suggestions = null;

    /** @param  list<int|float>|null  $suggestions */
    public static function on(string $field, string $message, string $reason = 'invalid', ?array $suggestions = null): self
    {
        // Built without the Validator facade so the resolver stays usable in pure unit tests.
        $validator = new Validator(new Translator(new ArrayLoader, 'pt_BR'), [], []);
        $e = new self($validator);
        $e->message = $message;
        $e->messages = [$field => [$message]];
        $e->field = $field;
        $e->reason = $reason;
        $e->suggestions = $suggestions;

        return $e;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->messages;
    }

    public function message(): string
    {
        return (string) ($this->errors()[$this->field][0] ?? $this->getMessage());
    }

    public function withField(string $field): self
    {
        return self::on($field, $this->message(), $this->reason, $this->suggestions);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->message(), 'errors' => $this->errors()];
        if ($this->suggestions !== null) {
            $body['details'] = [$this->field => ['suggestions' => $this->suggestions]];
        }

        return new JsonResponse($body, 422);
    }
}
