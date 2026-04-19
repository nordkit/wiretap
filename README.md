# Wiretap

Tap into your app's HTTP traffic. Filter, redact, and store inbound and outbound requests with zero boilerplate.

A highly configurable HTTP tracing package, built for Laravel. Wiretap automatically captures every outbound request and response — and optionally every inbound request too — including headers, payloads, status codes, and timing. Stores them securely with full control over what gets kept, what gets scrubbed, and where it all ends up. Works with any PHP application via a lightweight `TraceWriter` interface.

### Key Features

- **Storage Backends**: Persist traces to your SQL `database` (default) or stream them to a structured Laravel `log` channel.
- **Automatic Laravel Integration**: Zero-config capture of all Laravel HTTP Client requests via event listeners. Opt-in capture of all inbound requests via global middleware.
- **Native Guzzle Support**: Drop-in `WiretapClient` wrapper and `WiretapMiddleware` for existing Guzzle stacks.
- **Advanced Redaction**: Automatically scrubs sensitive headers and recursively redacts JSON payload keys before anything touches storage.
- **Filtering & Truncation**: Allowlist/denylist hosts, exclude URL patterns, and cap body sizes to keep your storage lean.
- **Eloquent Polymorphism**: Attach traces to any Eloquent model with `withTraceable()` / `->traceable()` and `HasTraces` — then query them back in one line.
- **Manual Tracing**: Use `Wiretap::trace()` to capture requests from raw cURL, custom SDKs, or any HTTP client — with built-in timing and safe exception handling.

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

If you are using this package in a standalone PHP application (without the Laravel framework), you will need to manually handle the database schema or inject a custom `TraceWriter` into the `Wiretap`.

If you choose to use the built-in database writer (which depends on `illuminate/database`), you must manually run this equivalent raw SQL to create the `wiretap_traces` table:

```sql
CREATE TABLE `wiretap_traces` (
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
  `traceable_type` VARCHAR(255) NULL,
  `traceable_id` CHAR(26) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `wiretap_traces_traceable_type_traceable_id_created_at_index` (`traceable_type`, `traceable_id`, `created_at`)
);
```

To entirely bypass `illuminate/database`, you can implement the `TraceWriter` interface and store your traces using raw PDO, Monolog, or any other solution:

```php
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpExchange;

class MyPdoWriter implements TraceWriter
{
    public function write(HttpExchange $exchange): void
    {
        // Insert $exchange data using raw PDO...
    }
}
```

## Usage

### Configuration

Publish the config file to get started:

```bash
php artisan vendor:publish --tag="wiretap-config"
```

Every option in `config/wiretap.php` is documented with an inline comment. The main areas to know about:

- **`enabled` / `debug`** — kill switch and error visibility. By default, all tracing exceptions are swallowed silently; set `debug = true` to forward them to Laravel's `report()` handler.
- **`driver`** — `database` (default, queued via `WriteTraceJob`) or `log` (streams to a Laravel log channel).
- **`outbound.*`** — controls the Laravel HTTP Client and Guzzle adapters, plus host/path filtering for outbound requests.
- **`inbound.*`** — opt-in capture of incoming requests. Disabled by default (`WIRETAP_INBOUND=false`). Includes the same host/path filtering as outbound.
- **`store_request_body` / `store_response_body`** — toggle body capture. Binary content types (`image/*`, `video/*`, `audio/*`, `multipart/form-data`, `application/octet-stream`, `application/pdf`, `application/zip`) are always stored as `null` regardless of this setting.
- **`max_body_bytes`** — caps stored body size to 64 KB by default. Set to `null` for unlimited.
- **`redact_request_headers` / `redact_response_headers` / `redact_body_keys`** — lists of headers and JSON keys to scrub before anything reaches storage.

### Laravel Integration

#### Laravel HTTP Client

The package automatically integrates with the Laravel HTTP Client. You don't need any additional setup. 

```php
use Illuminate\Support\Facades\Http;

$response = Http::withHeaders(['X-First' => 'foo'])
    ->get('https://api.github.com/users/octocat');

// The request and response are now automatically stored in the database.
```

#### Inbound HTTP Traffic

Wiretap can also capture all **incoming** requests to your application. This is disabled by default — opt-in by setting the environment variable:

```dotenv
WIRETAP_INBOUND=true
```

Or enable it directly in the config:

```php
'inbound' => [
    'laravel_http' => true,
],
```

When enabled, `WiretapInboundMiddleware` is automatically pushed onto the global HTTP kernel. Every request your app receives — and its response — will be traced through the same pipeline as outbound traffic, including redaction and filtering.

You can limit which inbound requests are traced using the `include_paths` and `exclude_paths` options. For example, to only trace webhook callbacks:

```php
'inbound' => [
    'laravel_http' => true,
    'include_paths' => ['#^/webhooks#'],
    'exclude_paths' => ['#^/health#'],
],
```

Or restrict tracing to a specific subdomain in a multi-domain app:

```php
'inbound' => [
    'laravel_http' => true,
    'include_hosts' => ['webhooks.myapp.com'],
],
```

> **Note:** `inbound.include_hosts` / `exclude_hosts` are matched against the `Host` header of the incoming request — i.e. your own app's domain. They are not matched against the remote caller's IP or hostname.

#### Polymorphic Relations

You can associate HTTP traces with your Eloquent models using polymorphic relations. This is useful for tracking which requests belong to a specific record, such as a user, order, or shipment.

When using the Laravel HTTP Client, you can use the built-in macro to attach a model to the request:

```php
use Illuminate\Support\Facades\Http;

$order = Order::find(1);

Http::withTraceable($order)
    ->post('https://api.example.com/orders/sync', $order->toArray());
```

> **Note:** `withTraceable()` stores the model in a per-process singleton and is designed for sequential requests. When using `Http::pool()` with multiple concurrent requests, only the most recently set traceable will be attached. For concurrent use-cases, construct an `HttpExchange` directly with the desired `traceable` and call `Wiretap::capture()` manually.

For **inbound** requests, attach a model to the trace using the `->traceable()` route macro. It scans the route's already-resolved model bindings and pushes the first match onto the trace scope:

```php
use App\Models\Order;

Route::post('/orders/{order}/sync', OrderSyncController::class)
    ->traceable(Order::class);
```

> **Note:** `->traceable()` relies on route model binding being resolved before it runs. Routes registered in the `web` or `api` middleware group satisfy this automatically via `SubstituteBindings`.

To easily retrieve the associated traces, add the provided trait (or manually define the `morphMany` relationship) on your Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;
use Nordkit\Wiretap\Laravel\Concerns\HasTraces;

class Order extends Model
{
    use HasTraces;
}
```

Now you can access the previous HTTP traces directly from your model instance:

```php
$traces = $order->traces;
```

### Guzzle HTTP Client

#### Automatic Logging (Recommended)

The package provides a `WiretapClient` wrapper that handles all logging automatically. It is registered as a singleton in the Laravel service container and can be injected directly via the constructor:

```php
use Nordkit\Wiretap\Guzzle\WiretapClient;

class GitHubService
{
    public function __construct(private readonly WiretapClient $http) {}

    public function getUser(string $username): array
    {
        $response = $this->http->get("https://api.github.com/users/{$username}");

        // The request and response are automatically logged.
        return json_decode((string) $response->getBody(), true);
    }
}
```

You can associate requests with an Eloquent model using `withTraceable()`, mirroring the Laravel HTTP Client behavior:

```php
$response = $this->http
    ->withTraceable($order)
    ->post('https://api.example.com/orders/sync', ['json' => $order->toArray()]);
```

> **Note:** `withTraceable()` is designed for sequential requests. The traceable is consumed (reset to `null`) after the next request is dispatched.

To pass custom Guzzle config options (e.g. `base_uri`, `timeout`), resolve the client manually with `WiretapClient::make()`:

```php
use Nordkit\Wiretap\Guzzle\WiretapClient;
use Nordkit\Wiretap\Wiretap;

$client = WiretapClient::make(app(\Nordkit\Wiretap\Wiretap::class), [
    'base_uri' => 'https://api.example.com',
    'timeout'  => 10,
]);
```

#### Manual Middleware

If you need to attach logging to an existing Guzzle `HandlerStack` (e.g. wrapping a third-party SDK), push the middleware directly:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Nordkit\Wiretap\Guzzle\WiretapMiddleware;
use Nordkit\Wiretap\Wiretap;

$stack = HandlerStack::create();
$stack->push(WiretapMiddleware::make(app(\Nordkit\Wiretap\Wiretap::class)));

$client = new Client(['handler' => $stack]);

$client->request('GET', 'https://api.github.com/repos/guzzle/guzzle');
```

### Manual Logging

If you need to trace requests made outside of Laravel's HTTP Client or Guzzle (for example, raw cURL requests or third-party SDKs), you can easily record entries using the built-in timer and the `Wiretap::trace()` helper method.

The `trace()` method safely swallows all exceptions, guaranteeing that tracing will never halt your application execution.

```php
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\Laravel\Facades\Wiretap;

// 1. Start the internal timer — returns a Closure that yields elapsed ms when called
$timer = Wiretap::start();

// 2. Perform your manual request/interaction...
$response = $customSdk->syncData(['foo' => 'bar']);

// 3. Trace the execution using the timer Closure
Wiretap::trace(
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
