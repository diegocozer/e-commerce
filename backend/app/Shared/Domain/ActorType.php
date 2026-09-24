<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Who performed an action (audit_logs.actor_type, order_status_history.actor_type). */
enum ActorType: string
{
    case Admin = 'admin';
    case Customer = 'customer';
    case System = 'system';
}
