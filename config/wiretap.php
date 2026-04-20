<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable / disable the logger globally
    |--------------------------------------------------------------------------
    */
    'enabled' => env('WIRETAP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Debug mode
    |--------------------------------------------------------------------------
    | When true, exceptions caught inside the logger are forwarded to Laravel's
    | report() handler (e.g. Sentry, Bugsnag, the log). Disabled by default so
    | logging failures never surface to the caller in production.
    */
    'debug' => env('WIRETAP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Database table name
    |--------------------------------------------------------------------------
    */
    'table_name' => 'wiretap_traces',

    /*
    |--------------------------------------------------------------------------
    | Eloquent model
    |--------------------------------------------------------------------------
    | Override to use a custom Trace model (e.g. to add casts, scopes, or a
    | different table name).
    */
    'model' => 'Nordkit\Wiretap\Laravel\Models\Trace',

    /*
    |--------------------------------------------------------------------------
    | Output Driver
    |--------------------------------------------------------------------------
    | Supported: 'database', 'log'.
    | Any other value will throw an InvalidArgumentException at boot time.
    */
    'driver' => env('WIRETAP_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | File Log Channel
    |--------------------------------------------------------------------------
    | If using the 'log' driver, optionally specify which logging channel to use.
    | Leave null to use the application's default log channel.
    */
    'log_channel' => env('WIRETAP_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Queue configuration
    |--------------------------------------------------------------------------
    | Set enabled = false to write logs synchronously (not recommended in prod).
    */
    'queue' => [
        'enabled' => env('WIRETAP_QUEUE_ENABLED', true),
        'connection' => env('WIRETAP_QUEUE_CONNECTION', null), // null = app default
        'name' => env('WIRETAP_QUEUE', 'wiretap'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound adapters
    |--------------------------------------------------------------------------
    | laravel_http: auto-listen to Laravel Http:: client events (WIRETAP_LARAVEL_HTTP).
    | guzzle:       when true, binds WiretapGuzzleClient as a singleton in the
    |               container. Inject WiretapGuzzleClient via the constructor
    |               or resolve it with app(WiretapGuzzleClient::class).
    |               Set to false to manage the HandlerStack manually instead.
    |               (WIRETAP_GUZZLE)
    */
    'outbound' => [
        'laravel_http' => env('WIRETAP_LARAVEL_HTTP', true),
        'guzzle' => env('WIRETAP_GUZZLE', true),

        /*
        | Host filtering for outbound requests.
        | Matched against the hostname of the URL being requested (the remote service).
        | include_hosts: empty = trace all. Non-empty = only these hosts. Supports wildcards.
        | exclude_hosts: always takes priority over include_hosts. Supports wildcards.
        | Example: exclude_hosts: ['*.internal.example.com']
        */
        'include_hosts' => [],
        'exclude_hosts' => [],

        /*
        | Regex patterns matched against the path component of the outbound URL (e.g. /api/payments).
        | include_paths: empty = trace all paths. Non-empty = only trace matching paths.
        | exclude_paths: always takes priority over include_paths. Matching requests are skipped.
        | Example: include_paths: ['#^/api/payments#'], exclude_paths: ['#^/health#']
        */
        'include_paths' => [],
        'exclude_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound adapter
    |--------------------------------------------------------------------------
    | laravel_http: when true, automatically pushes WiretapInboundMiddleware onto
    |               the global HTTP kernel, capturing all incoming requests and
    |               responses. Disabled by default — opt-in only.
    |               (WIRETAP_INBOUND)
    */
    'inbound' => [
        'laravel_http' => env('WIRETAP_INBOUND', false),

        /*
        | Host filtering for inbound requests.
        | Matched against the Host header of the incoming request (your app's domain).
        | Useful for multi-domain / multi-tenant apps, or to limit tracing to a specific
        | subdomain — e.g. include_hosts: ['webhooks.myapp.com'].
        | include_hosts: empty = trace all. Non-empty = only these hosts. Supports wildcards.
        | exclude_hosts: always takes priority over include_hosts. Supports wildcards.
        */
        'include_hosts' => [],
        'exclude_hosts' => [],

        /*
        | Regex patterns matched against the path component of the inbound URL (e.g. /webhooks/stripe).
        | include_paths: empty = trace all paths. Non-empty = only trace matching paths.
        | exclude_paths: always takes priority over include_paths. Matching requests are skipped.
        | Example: include_paths: ['#^/webhooks#'], exclude_paths: ['#^/health#']
        */
        'include_paths' => [],
        'exclude_paths' => [],

        /*
        | store_ip: when true, captures the caller's IP address (respects trusted
        | proxies configured via TrustProxies — uses $request->ip()).
        | Disabled by default. Enable only when needed; IP addresses are personal
        | data under GDPR and similar regulations.
        | (WIRETAP_INBOUND_STORE_IP)
        */
        'store_ip' => env('WIRETAP_INBOUND_STORE_IP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    | When enabled, `php artisan wiretap:prune` is automatically scheduled daily
    | by the service provider — no manual scheduler entry required.
    | Run it manually at any time: php artisan wiretap:prune --days=30
    |
    | Note: pruning only applies to the 'database' driver. It has no effect
    | when wiretap.driver is set to 'log'.
    */

    'pruning' => [
        'enabled' => env('WIRETAP_PRUNING_ENABLED', false),
        'keep_days' => env('WIRETAP_PRUNING_KEEP_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | store_request_body / store_response_body: set to false to always discard
    |   the body, regardless of content type (e.g. for privacy or storage reasons).
    |
    | Bodies with inherently binary content types cannot be meaningfully redacted
    | or stored as text. Instead of null, a '[binary: filename.ext]' placeholder
    | is stored. The filename is extracted from the Content-Disposition header or
    | the multipart part header when available; otherwise the content type is used
    | as the fallback — e.g. '[binary: image/png]'.
    | Affected types: image/*, video/*, audio/*, multipart/form-data,
    | application/octet-stream, application/pdf, application/zip,
    | application/gzip, application/x-tar.
    |
    | max_body_bytes: caps the stored body length. Bodies exceeding this limit
    |   are truncated and suffixed with "... [TRUNCATED]". Set to null for unlimited.
    */
    'store_request_body' => env('WIRETAP_STORE_REQUEST_BODY', true),
    'store_response_body' => env('WIRETAP_STORE_RESPONSE_BODY', true),
    'max_body_bytes' => env('WIRETAP_MAX_BODY_BYTES', 65_536), // 64 KB; null = unlimited

    /*
    |--------------------------------------------------------------------------
    | Header redaction
    |--------------------------------------------------------------------------
    | Case-insensitive header names replaced with "[REDACTED]" before storage.
    */
    'redact_string' => '[REDACTED]',

    'redact_request_headers' => [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'cookie',
    ],

    'redact_response_headers' => [
        'set-cookie',
    ],

    /*
    |--------------------------------------------------------------------------
    | Body key redaction
    |--------------------------------------------------------------------------
    | JSON body keys (recursive, case-insensitive) replaced with "[REDACTED]".
    */
    'redact_body_keys' => [
        'password',
        'token',
        'secret',
        'api_key',
        'access_token',
        'refresh_token',
        'card_number',
        'cvv',
    ],
];
