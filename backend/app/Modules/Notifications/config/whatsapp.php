<?php

/* WhatsApp channel (ARCHITECTURE.md §7.5): `log` (MVP stub) | `null`. Enabled by the setting notifications.whatsapp_enabled. */
return [
    'driver' => env('WHATSAPP_DRIVER', env('APP_ENV') === 'testing' ? 'null' : 'log'),
];
