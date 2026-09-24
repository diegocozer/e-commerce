<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;
use Illuminate\Support\Facades\Auth;
use JsonSerializable;
use Stringable;

/** Reference to the actor of an action: {type: admin|customer|system, id: ?int}. */
final readonly class ActorRef implements JsonSerializable, Stringable
{
    private function __construct(public ActorType $type, public ?int $id)
    {
        if (($type === ActorType::System) !== ($id === null)) {
            throw InvalidValue::because('System actors have no id; admin/customer actors require one.');
        }
    }

    public static function admin(int $id): self
    {
        return new self(ActorType::Admin, $id);
    }

    public static function customer(int $id): self
    {
        return new self(ActorType::Customer, $id);
    }

    public static function system(): self
    {
        return new self(ActorType::System, null);
    }

    public static function of(ActorType $type, ?int $id): self
    {
        return new self($type, $id);
    }

    /** Actor of the current request: admin guard first, then customer, else system. */
    public static function current(): self
    {
        $adminId = Auth::guard('admin')->id();
        if ($adminId !== null) {
            return self::admin((int) $adminId);
        }

        $customerId = Auth::guard('customer')->id();
        if ($customerId !== null) {
            return self::customer((int) $customerId);
        }

        return self::system();
    }

    public function isSystem(): bool
    {
        return $this->type === ActorType::System;
    }

    /** @return array{type: string, id: int|null} */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type->value, 'id' => $this->id];
    }

    /** "admin:12", "customer:345", "system" (log format, ARCHITECTURE.md §10.1). */
    public function __toString(): string
    {
        return $this->id === null ? $this->type->value : "{$this->type->value}:{$this->id}";
    }
}
