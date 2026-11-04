<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IP addresses or CIDR ranges of reverse proxies in front of
    | the application, e.g. the Docker network shared with another server's
    | Caddy (compose.shared.yaml). Only requests coming from these addresses
    | may set the visitor's IP address (X-Forwarded-For) and HTTPS
    | (X-Forwarded-Proto); the other forwarded headers are never trusted
    | (bootstrap/app.php). Empty: no proxy is trusted.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
