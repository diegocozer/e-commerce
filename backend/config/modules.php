<?php

/*
|--------------------------------------------------------------------------
| Modules and allowed dependencies (ADR-018, ARCHITECTURE.md §2.3 / §2.5)
|--------------------------------------------------------------------------
|
| Single source of truth for the module dependency graph. It is enforced by
| tests/Architecture/ModuleDependenciesTest. Every module may use App\Shared.
| "A => [B, C]" means A may import from B and C (only from their public
| namespaces listed in 'public_namespaces', see ARCHITECTURE.md §2.3).
| Changing this graph requires an ADR.
|
*/

return [

    'dependencies' => [
        'Settings' => [],
        'Identity' => [],
        'Audit' => [],
        'Customers' => ['Settings'],
        'Inventory' => ['Settings'],
        'Pricing' => ['Customers'],
        'Shipping' => ['Settings'],
        'Payments' => ['Settings'],
        'Catalog' => ['Pricing', 'Inventory', 'Settings'],
        'Cart' => ['Catalog', 'Pricing', 'Inventory', 'Shipping', 'Customers', 'Settings'],
        'Orders' => ['Inventory', 'Payments', 'Pricing', 'Customers', 'Settings'],
        'Checkout' => ['Cart', 'Orders', 'Payments', 'Pricing', 'Shipping', 'Inventory', 'Customers', 'Settings'],
        'Notifications' => ['Orders', 'Payments', 'Customers', 'Inventory', 'Catalog', 'Identity', 'Settings'],
        'Reports' => ['Orders', 'Payments', 'Catalog', 'Customers', 'Inventory'],
        'Seo' => ['Catalog', 'Settings'],
    ],

    /*
    | Sub-namespaces of a module that other (allowed) modules may import.
    | Models are allowed for read-only Eloquent relations (rule 2 of §2.3);
    | writing to another module's tables must go through its Contracts.
    */
    'public_namespaces' => ['Contracts', 'DTOs', 'Events', 'Enums', 'Exceptions', 'Models'],

];
