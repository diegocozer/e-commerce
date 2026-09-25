<?php

declare(strict_types=1);

namespace App\Modules\Settings\Events;

use App\Shared\Domain\ActorRef;

final readonly class SettingsUpdated
{
    /** @param  list<string>  $keys */
    public function __construct(public array $keys, public ActorRef $actor) {}
}
