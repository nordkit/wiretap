# Wiretap

Tap into your app's HTTP traffic. Log, filter, and redact requests with zero boilerplate.

A robust, highly configurable HTTP traffic logger for Laravel applications and Guzzle HTTP clients. Monitor and store outbound API requests with full visibility into third-party integrations, webhook deliveries, and external service calls.

### Key Features

- **Store Options**: Output logs securely to your SQL `database` (default) or redirect them to a structured Laravel `log` file based on configuration.
- **Automatic Laravel Integration**: Seamlessly attaches to Laravel's HTTP Client (`Illuminate\Support\Facades\Http`).
- **Native Guzzle Support**: Includes middleware for easily logging raw Guzzle requests.
- **Advanced Redaction & Security**: Automatically redacts sensitive headers (e.g., API keys, Authorization tokens) and recursively scrubs sensitive JSON payload keys before persisting to the database.
- **Filtering & Truncation**: Configure maximum payload sizes to preserve database space, and strictly control which requests should be logged.
- **Eloquent Polymorphism**: Tie HTTP requests directly to Eloquent models using the `withLoggable()` macro and `HasHttpLogs` trait.
- **Manual Logging Support**: Use the `Wiretap` facade to record logs from custom auto-generated SDKs or vanilla cURL scripts.

## Requirements

- PHP 8.3+
- Laravel 11.0 / 12.0 / 13.0

## Installation

You can install the package via composer:

```bash
composer require nordkit/wiretap
```

### Laravel Projects

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="wiretap-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="wiretap-config"
```

### Non-Laravel Projects

If you are using this package in a standalone PHP application (without the Laravel framework), you will need to manually handle the database schema or inject a custom `HttpLogWriter` into the `Wiretap`.

If you choose to use the built-in database writer (which depends on `illuminate/database`), you must manually run this equivalent raw SQL to create the `http_logs` table:

```sql
CREATE TABLE `http_logs` (
  `id` CHAR(26) NOT NULL,
  `direction` VARCHAR(10) NOT NULL,
  `driver` VARCHAR(20) NOT NULL,
  `url` TEXT NOT NULL,
  `method` VARCHAR(10) NOT NULL,
  `request_headers` JSON NULL,
  `request_body` LONGTEXT NULL,
  `response_status` INT NULL,
  `response_headers` JSON NULL,
  `response_body` LONGTEXT NULL,
  `duration_ms` INT UNSIGNED NOT NULL,
  `error_message` TEXT NULL,
  `loggable_type` VARCHAR(255) NULL,
  `loggable_id` CHAR(26) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `http_logs_loggable_type_loggable_id_created_at_index` (`loggable_type`, `loggable_id`, `created_at`)
);
```

To entirely bypass `illuminate/database`, you can implement the `HttpLogWriter` interface and store your logs using raw PDO, Monolog, or any other solution:

```php
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\HttpLogEntry;

class MyPdoWriter implements HttpLogWriter
{
    public function write(HttpLogEntry $entry): void
    {
        // Insert $entry data using raw PDO...
    }
}
```

## Usage

### Configuration Reference

All settings are configured via `config/wiretap.php`. Below are the available keys, their corresponding environment variables, and descriptions:

| Config Key | Environment Variable | Default | Description |
|---|---|---|---|
| `enabled` | `HTTP_LOGGER_ENABLED` | `true` | Globally enable or disable logging. |
| `debug` | `HTTP_LOGGER_DEBUG` | `false` | When true, logging exceptions are forwarded to Laravel's `report()` handler rather than swallowed. |
| `table_name` | — | `http_logs` | The database table used by the Eloquent model. |
| `model` | — | `HttpLog::class` | Override this to use a custom Eloquent model. |
| `driver` | `HTTP_LOGGER_DRIVER` | `database` | Storage backend. Supported: `database`, `log`. |
| `log_channel` | `HTTP_LOGGER_CHANNEL` | `null` | Specify which channel to use when the driver is `log`. Leaves as null to use the default app channel. |
| `queue.enabled` | `HTTP_LOGGER_QUEUE_ENABLED` | `true` | Queue logs for async writes. (Set to false for synchronous storage—not recommended for production). |
| `queue.connection`| `HTTP_LOGGER_QUEUE_CONNECTION`| `null` | The queue connection to use (null defaults to app default). |
| `queue.name` | `HTTP_LOGGER_QUEUE` | `logging` | The queue name to push log jobs into. |
| `outbound.laravel_http`| `HTTP_LOGGER_LARAVEL_HTTP`| `true` | Automatically listen to Laravel Http Client events. |
| `outbound.guzzle`| `HTTP_LOGGER_GUZZLE`| `true` | Bind `LoggingClient` into the application container automatically. |
| `log_request_body`| `HTTP_LOGGER_LOG_REQUEST_BODY`| `true` | Capture the raw HTTP request body. |
| `log_response_body`| `HTTP_LOGGER_LOG_RESPONSE_BODY`| `true`| Capture the raw HTTP response body. |
| `max_body_bytes` | `HTTP_LOGGER_MAX_BODY_BYTES`| `65536` | Maximum size in bytes of retained bodies (64 KB). Null for unlimited. |
| `include_hosts` | — | `[]` | Only log requests to these hosts. Wildcards supported (e.g., `*.api.com`). |
| `exclude_hosts` | — | `[]` | Skip logging to these hosts. Takes priority over `include_hosts`. |
| `exclude_paths` | — | `[]` | Skip logging requests when full URLs match these regex patterns. |
| `redact_string` | — | `[REDACTED]` | String value used to replace redacted content. |
| `redact_request_headers`| — | `[...]` | List of case-insensitive request headers to redact. |
| `redact_response_headers`| — | `[...]` | List of case-insensitive response headers to redact. |
| `redact_body_keys`| — | `[...]` | List of JSON body keys to scrub recursively. |

### Laravel Integration

#### Laravel HTTP Client

The package automatically integrates with the Laravel HTTP Client. You don't need any additional setup. 

```php
use Illuminate\Support\Facades\Http;

$response = Http::withHeaders(['X-First' => 'foo'])
    ->get('https://api.github.com/users/octocat');

// The request and response are now automatically stored in the database.
```

#### Polymorphic Relations

You can associate HTTP request logs with your Eloquent models using polymorphic relations. This is useful for tracking which requests belong to a specific record, such as a user, order, or shipment.

When using the Laravel HTTP Client, you can use the built-in macro to attach a model to the request:

```php
use Illuminate\Support\Facades\Http;

$order = Order::find(1);

Http::withLoggable($order)
    ->post('https://api.example.com/orders/sync', $order->toArray());
```

> **Note:** `withLoggable()` stores the model in a per-process singleton and is designed for sequential requests. When using `Http::pool()` with multiple concurrent requests, only the most recently set loggable will be attached. For concurrent use-cases, construct an `HttpLogEntry` directly with the desired `loggable` and call `Wiretap::record()` manually.

To easily retrieve the associated logs, add the provided trait (or manually define the `morphMany` relationship) on your Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;
use Nordkit\Wiretap\Laravel\Concerns\HasHttpLogs;

class Order extends Model
{
    use HasHttpLogs;
}
```

Now you can access the previous HTTP request logs directly from your model instance:

```php
$logs = $order->httpLogs;
```

### Guzzle HTTP Client

#### Automatic Logging (Recommended)

The package provides a `LoggingClient` wrapper that handles all logging automatically. It is registered as a singleton in the Laravel service container and can be injected directly via the constructor:

```php
use Nordkit\Wiretap\Guzzle\LoggingClient;

class GitHubService
{
    public function __construct(private readonly LoggingClient $http) {}

    public function getUser(string $username): array
    {
        $response = $this->http->get("https://api.github.com/users/{$username}");

        // The request and response are automatically logged.
        return json_decode((string) $response->getBody(), true);
    }
}
```

You can associate requests with an Eloquent model using `withLoggable()`, mirroring the Laravel HTTP Client behavior:

```php
$response = $this->http
    ->withLoggable($order)
    ->post('https://api.example.com/orders/sync', ['json' => $order->toArray()]);
```

> **Note:** `withLoggable()` is designed for sequential requests. The loggable is consumed (reset to `null`) after the next request is dispatched.

To pass custom Guzzle config options (e.g. `base_uri`, `timeout`), resolve the client manually with `LoggingClient::make()`:

```php
use Nordkit\Wiretap\Guzzle\LoggingClient;
use Nordkit\Wiretap\Wiretap;

$client = LoggingClient::make(app(\Nordkit\Wiretap\Wiretap::class), [
    'base_uri' => 'https://api.example.com',
    'timeout'  => 10,
]);
```

#### Manual Middleware

If you need to attach logging to an existing Guzzle `HandlerStack` (e.g. wrapping a third-party SDK), push the middleware directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Nordkit\Wiretap\Guzzle\LoggingMiddleware;
use Nordkit\Wiretap\Wiretap;

$stack = HandlerStack::create();
$stack->push(LoggingMiddleware::make(app(\Nordkit\Wiretap\Wiretap::class)));

$client = new Client(['handler' => $stack]);

$client->request('GET', 'https://api.github.com/repos/guzzle/guzzle');
```

### Manual Logging

If you need to log requests made outside of Laravel's HTTP Client or Guzzle (for example, raw cURL requests or third-party SDKs), you can easily record entries using the built-in timer and the `Wiretap::log()` helper method. 

The `log()` method safely swallows all exceptions, guaranteeing that logging will never halt your application execution.

```php
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\Laravel\Facades\Wiretap;

// 1. Start the internal logger timer — returns a Closure that yields elapsed ms when called
$timer = Wiretap::startTimer();

// 2. Perform your manual request/interaction...
$response = $customSdk->syncData(['foo' => 'bar']);

// 3. Log the execution using the timer Closure
Wiretap::log(
    direction: HttpDirection::Outbound,
    driver: 'custom-sdk',
    url: 'https://api.example.com/sync',
    method: 'POST',
    requestHeaders: ['Content-Type' => 'application/json'],
    requestBody: json_encode(['data' => 'sync-me']),
    responseStatus: 200,
    responseHeaders: ['Content-Type' => 'application/json'],
    responseBody: json_encode(['status' => 'success']),
    timer: $timer, // Automagically resolves the request duration
    errorMessage: null, // Populate if your manual implementation encountered an error
);
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Releasing

Please see [RELEASING](RELEASING.md) for instructions on how to cut a new release.

## Security Vulnerabilities

Please review [our security policy](https://github.com/nordkit/wiretap/security/policy) on how to report security vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
