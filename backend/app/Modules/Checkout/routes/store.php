<?php

/*
|--------------------------------------------------------------------------
| Checkout — POST /checkout and /checkout/preview (API.md §1.1)
|--------------------------------------------------------------------------
| Prefix: /api/v1 · no automatic name prefix: name routes checkout.*
| middleware: api. Add auth:customer and throttle:checkout / throttle:customer per route.
| Loaded automatically by App\Shared\Providers\ModuleServiceProvider.
*/
