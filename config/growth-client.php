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

    /*
    | The ESTATE key (`growth:issue-key <product> --admin`): may act for any product with ?as_product=.
    | Only the shirah.co portal holds one. A product leaves this empty, and the proxy then refuses
    | ?as_product= itself, plainly, without calling the service.
    */
    'estate_key' => env('GROWTH_SERVICE_ADMIN_KEY', ''),

    /* A backstop against a hung socket. Growth calls are database reads and writes, not AI. */
    'timeout' => (int) env('GROWTH_SERVICE_TIMEOUT', 10),

    /*
    | The secret growth-service signs this product's callbacks with (base64, printed once by the
    | service). Empty = the receiver refuses everything: the signature is its whole defence.
    */
    'callback_secret' => env('GROWTH_SERVICE_CALLBACK_SECRET', ''),

    /*
    | Who is acting, from THIS product's session (F1 rule 7). An invokable class implementing
    | Laravel\GrowthActorResolver; null = the signed-in user's id and email.
    */
    'actor' => null,

    /*
    | This product's official figures, for Radar and post facts (G3c): a class implementing
    | Facts\FactsAdapter. Null = the signed facts route answers "not available".
    */
    'facts' => null,

];
