<?php


return [
    /*
    |--------------------------------------------------------------------------
    | Enable / disable the logger globally
    |--------------------------------------------------------------------------
    */
    'enabled' => env('HTTP_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Debug mode
    |--------------------------------------------------------------------------
    | When true, exceptions caught inside the logger are forwarded to Laravel's
    | report() handler (e.g. Sentry, Bugsnag, the log). Disabled by default so
    | logging failures never surface to the caller in production.
    */
    'debug' => env('HTTP_LOGGER_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Database table name
    |--------------------------------------------------------------------------
    */
    'table_name' => 'http_logs',

    /*
    |--------------------------------------------------------------------------
    | Eloquent model
    |--------------------------------------------------------------------------
    | Override to use a custom HttpLog model (e.g. to add casts, scopes, or a
    | different table name).
    */
    'model' => 'Nordkit\Wiretap\Laravel\Models\HttpLog',

    /*
    |--------------------------------------------------------------------------
    | Output Driver
    |--------------------------------------------------------------------------
    | Supported: 'database', 'log'.
    | Any other value will throw an InvalidArgumentException at boot time.
    */
    'driver' => env('HTTP_LOGGER_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | File Log Channel
    |--------------------------------------------------------------------------
    | If using the 'log' driver, optionally specify which logging channel to use.
    | Leave null to use the application's default log channel.
    */
    'log_channel' => env('HTTP_LOGGER_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Queue configuration
    |--------------------------------------------------------------------------
    | Set enabled = false to write logs synchronously (not recommended in prod).
    */
    'queue' => [
        'enabled' => env('HTTP_LOGGER_QUEUE_ENABLED', true),
        'connection' => env('HTTP_LOGGER_QUEUE_CONNECTION', null), // null = app default
        'name' => env('HTTP_LOGGER_QUEUE', 'logging'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound adapters
    |--------------------------------------------------------------------------
    | laravel_http: auto-listen to Laravel Http:: client events (HTTP_LOGGER_LARAVEL_HTTP).
    | guzzle:       when true, binds WiretapGuzzleClient as a singleton in the
    |               container. Inject WiretapGuzzleClient via the constructor
    |               or resolve it with app(WiretapGuzzleClient::class).
    |               Set to false to manage the HandlerStack manually instead.
    |               (HTTP_LOGGER_GUZZLE)
    */
    'outbound' => [
        'laravel_http' => env('HTTP_LOGGER_LARAVEL_HTTP', true),
        'guzzle' => env('HTTP_LOGGER_GUZZLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Body capture
    |--------------------------------------------------------------------------
    */
    'log_request_body' => env('HTTP_LOGGER_LOG_REQUEST_BODY', true),
    'log_response_body' => env('HTTP_LOGGER_LOG_RESPONSE_BODY', true),
    'max_body_bytes' => env('HTTP_LOGGER_MAX_BODY_BYTES', 65_536), // 64 KB; null = unlimited

    /*
    |--------------------------------------------------------------------------
    | Host filtering
    |--------------------------------------------------------------------------
    | include_hosts: empty = allow all. Non-empty = only log these hosts.
    | exclude_hosts: always takes priority over include_hosts.
    | Supports wildcards, e.g. "*.internal.example.com"
    */
    'include_hosts' => [],
    'exclude_hosts' => [],

    /*
    |--------------------------------------------------------------------------
    | Path filtering
    |--------------------------------------------------------------------------
    | Regex patterns matched against the full URL. Matching URLs are skipped.
    | Example: ['#/health#', '#/metrics#']
    */
    'exclude_paths' => [],

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
