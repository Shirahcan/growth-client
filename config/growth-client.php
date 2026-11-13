<?php

return [

    /*
    | Loopback, always: growth-service has no public surface. Visitors reach published content through
    | this product's own server-side proxy, which calls this URL.
    */
    'base_url' => env('GROWTH_SERVICE_URL', 'http://127.0.0.1:8011'),

    /*
    | Issued BY the service (`php artisan growth:issue-key <product>`): the callee owns the key, so it can
    | revoke it. The product is derived from this key on the service and never from anything we send.
    */
    'trust_key' => env('GROWTH_SERVICE_TRUST_KEY', ''),

    /* A backstop against a hung socket. Growth calls are database reads and writes, not AI. */
    'timeout' => (int) env('GROWTH_SERVICE_TIMEOUT', 10),

];
