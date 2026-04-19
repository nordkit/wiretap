# Rename Plan: v2.0.0

Refactor the package naming to use domain-specific, storage-agnostic names.
`HttpExchange` represents the transient HTTP request/response cycle.
`Trace` represents the persisted observability record.
`loggable` → `traceable` throughout.

---

## File Renames

| Current | After |
|---|---|
| `src/HttpLogEntry.php` | `src/HttpExchange.php` |
| `src/HttpLogFilter.php` | `src/Pipeline/TraceFilter.php` |
| `src/HttpLogRedactor.php` | `src/Pipeline/TraceRedactor.php` |
| `src/Contracts/HttpLogWriter.php` | `src/Contracts/TraceWriter.php` |
| `src/Laravel/LoggableScope.php` | `src/Laravel/TraceableScope.php` |
| `src/Laravel/Models/HttpLog.php` | `src/Laravel/Models/Trace.php` |
| `src/Laravel/Concerns/HasHttpLogs.php` | `src/Laravel/Concerns/HasTraces.php` |
| `src/Laravel/Jobs/WriteHttpLogJob.php` | `src/Laravel/Jobs/WriteTraceJob.php` |
| `src/Laravel/Writers/EloquentWriter.php` | `src/Laravel/Writers/DatabaseWriter.php` |
| `database/migrations/2024_01_01_000000_create_http_logs_table.php` | `database/migrations/2024_01_01_000000_create_traces_table.php` |

### Unchanged files
- `src/Concerns/FlattensHeaders.php`
- `src/HttpDirection.php`
- `src/Wiretap.php` (class name unchanged, internals updated)
- `src/Guzzle/WiretapClient.php` (internals updated)
- `src/Guzzle/WiretapMiddleware.php` (internals updated)
- `src/Laravel/Facades/Wiretap.php`
- `src/Laravel/Listeners/RecordOutboundRequest.php` (internals updated)
- `src/Laravel/Listeners/RecordFailedConnection.php` (internals updated)
- `src/Laravel/Writers/LogWriter.php` (internals updated)
- `src/Laravel/WiretapServiceProvider.php` (internals updated)

---

## Final File Structure

```
src/
├── Concerns/
│   └── FlattensHeaders.php
├── Contracts/
│   └── TraceWriter.php
├── Guzzle/
│   ├── WiretapClient.php
│   └── WiretapMiddleware.php
├── Pipeline/
│   ├── TraceFilter.php
│   └── TraceRedactor.php
├── HttpDirection.php
├── HttpExchange.php
├── Wiretap.php
└── Laravel/
    ├── Concerns/
    │   └── HasTraces.php
    ├── Facades/
    │   └── Wiretap.php
    ├── Jobs/
    │   └── WriteTraceJob.php
    ├── Listeners/
    │   ├── RecordFailedConnection.php
    │   └── RecordOutboundRequest.php
    ├── Models/
    │   └── Trace.php
    ├── Writers/
    │   ├── DatabaseWriter.php
    │   └── LogWriter.php
    ├── TraceableScope.php
    └── WiretapServiceProvider.php
```

---

## Class & Method Renames

### `HttpLogEntry` → `HttpExchange`
- Namespace: `Nordkit\Wiretap\HttpExchange`
- Property `$loggable` → `$traceable`
- Updated in: `HttpLogRedactor`, `HttpLogFilter`, `Wiretap`, `WriteHttpLogJob`, `RecordOutboundRequest`, `RecordFailedConnection`, `WiretapMiddleware`, `LogWriter`

### `HttpLogFilter` → `Pipeline\TraceFilter`
- Namespace: `Nordkit\Wiretap\Pipeline\TraceFilter`
- Method `shouldLog(HttpLogEntry)` → `shouldTrace(HttpExchange)`
- Updated in: `Wiretap`, `WiretapServiceProvider`

### `HttpLogRedactor` → `Pipeline\TraceRedactor`
- Namespace: `Nordkit\Wiretap\Pipeline\TraceRedactor`
- Method `redact(HttpLogEntry)` → `redact(HttpExchange)`
- Updated in: `Wiretap`, `WiretapServiceProvider`
- Config keys passed to constructor: `log_request_body` → `store_request_body`, `log_response_body` → `store_response_body`

### `Contracts\HttpLogWriter` → `Contracts\TraceWriter`
- Namespace: `Nordkit\Wiretap\Contracts\TraceWriter`
- Method `write(HttpLogEntry)` → `write(HttpExchange)`
- Updated in: `WiretapServiceProvider`, `EloquentWriter`, `LogWriter`

### `EloquentWriter` → `DatabaseWriter`
- Namespace: `Nordkit\Wiretap\Laravel\Writers\DatabaseWriter`
- Property `$loggableType`, `$loggableId` → `$traceableType`, `$traceableId`
- Updated in: `WiretapServiceProvider`

### `LogWriter`
- Namespace unchanged: `Nordkit\Wiretap\Laravel\Writers\LogWriter`
- `implements HttpLogWriter` → `implements TraceWriter`
- `write(HttpLogEntry)` → `write(HttpExchange)`
- Log message: `"HTTP Log: ..."` → `"Wiretap: ..."`

### `LoggableScope` → `TraceableScope`
- Namespace: `Nordkit\Wiretap\Laravel\TraceableScope`
- Internal `$loggable` → `$traceable`
- `push(object $loggable)` parameter → `push(object $traceable)`
- Updated in: `WiretapServiceProvider`, `RecordOutboundRequest`

### `HttpLog` model → `Trace`
- Namespace: `Nordkit\Wiretap\Laravel\Models\Trace`
- `$table = 'http_logs'` → `$table = 'traces'`
- Column `loggable_type`, `loggable_id` → `traceable_type`, `traceable_id`
- Relation method `loggable()` → `traceable()`
- Updated in: `WriteHttpLogJob`, `HasHttpLogs`, `WiretapServiceProvider`

### `HasHttpLogs` → `HasTraces`
- Namespace: `Nordkit\Wiretap\Laravel\Concerns\HasTraces`
- Method `httpLogs()` → `traces()`
- Morph second arg `'loggable'` → `'traceable'`

### `WriteHttpLogJob` → `WriteTraceJob`
- Namespace: `Nordkit\Wiretap\Laravel\Jobs\WriteTraceJob`
- Property `$entry: HttpLogEntry` → `$exchange: HttpExchange`
- Properties `$loggableType`, `$loggableId` → `$traceableType`, `$traceableId`
- `handle(HttpLog $httpLog)` → `handle(Trace $trace)`
- DB columns `loggable_type`, `loggable_id` → `traceable_type`, `traceable_id`
- Updated in: `DatabaseWriter`

### `Wiretap` methods
- `log(...)` → `trace(...)` (public manual API)
- `record(HttpLogEntry)` → `capture(HttpExchange)` (internal pipeline API)
- Parameter `$loggable` → `$traceable` in `trace()`
- Updated in: `RecordOutboundRequest`, `RecordFailedConnection`, `WiretapMiddleware`, `WiretapClient`, Facade

### `WiretapClient`
- `withLoggable(object)` → `withTraceable(object)`
- Internal `$loggable` → `$traceable`, `consumeLoggable()` → `consumeTraceable()`

### `WiretapMiddleware`
- All `$loggable` references → `$traceable`

### `Http::withLoggable()` macro → `Http::withTraceable()`
- Updated in: `WiretapServiceProvider`

---

## Config Changes (`config/wiretap.php`)

| Current key | New key                                         |
|---|-------------------------------------------------|
| `table_name: 'http_logs'` | `table_name: 'traces'`                          |
| `model: '...HttpLog'` | `model: 'Nordkit\Wiretap\Laravel\Models\Trace'` |
| `log_request_body` | `store_request_body`                            |
| `log_response_body` | `store_response_body`                           |

### Environment variable renames

| Current | New                           |
|---|-------------------------------|
| `HTTP_LOGGER_ENABLED` | `WIRETAP_ENABLED`             |
| `HTTP_LOGGER_DEBUG` | `WIRETAP_DEBUG`               |
| `HTTP_LOGGER_DRIVER` | `WIRETAP_DRIVER`              |
| `HTTP_LOGGER_CHANNEL` | `WIRETAP_LOG_CHANNEL`         |
| `HTTP_LOGGER_QUEUE_ENABLED` | `WIRETAP_QUEUE_ENABLED`       |
| `HTTP_LOGGER_QUEUE_CONNECTION` | `WIRETAP_QUEUE_CONNECTION`    |
| `HTTP_LOGGER_QUEUE` | `WIRETAP_QUEUE`               |
| `HTTP_LOGGER_LARAVEL_HTTP` | `WIRETAP_LARAVEL_HTTP`        |
| `HTTP_LOGGER_GUZZLE` | `WIRETAP_GUZZLE`              |
| `HTTP_LOGGER_LOG_REQUEST_BODY` | `WIRETAP_STORE_REQUEST_BODY`  |
| `HTTP_LOGGER_LOG_RESPONSE_BODY` | `WIRETAP_STORE_RESPONSE_BODY` |
| `HTTP_LOGGER_MAX_BODY_BYTES` | `WIRETAP_MAX_BODY_BYTES`      |

---

## Migration Changes

- Rename file: `create_http_logs_table.php` → `create_traces_table.php`
- Table: `http_logs` → `traces`
- Columns: `loggable_type`, `loggable_id` → `traceable_type`, `traceable_id`
- Morph: `nullableUlidMorphs('loggable')` → `nullableUlidMorphs('traceable')`
- Index: update column names accordingly

---

## Test Renames

### File Renames

| Current | After |
|---|---|
| `tests/Feature/HasHttpLogsTest.php` | `tests/Feature/HasTracesTest.php` |
| `tests/Feature/WriteHttpLogJobTest.php` | `tests/Feature/WriteTraceJobTest.php` |
| `tests/Unit/HttpLogFilterTest.php` | `tests/Unit/TraceFilterTest.php` |
| `tests/Unit/HttpLogRedactorTest.php` | `tests/Unit/TraceRedactorTest.php` |

### Unchanged test files (internals updated only)
- `tests/Feature/LogWriterTest.php`
- `tests/Feature/WiretapIntegrationTest.php`
- `tests/Feature/WiretapServiceProviderTest.php`
- `tests/Unit/ListenerTest.php`
- `tests/Unit/WiretapClientTest.php`
- `tests/Unit/WiretapMiddlewareTest.php`
- `tests/Unit/WiretapTest.php`
- `tests/Pest.php`
- `tests/TestCase.php`

### Internal test changes

- **HasTracesTest**: Update class references `HasHttpLogs` → `HasTraces`, method `httpLogs()` → `traces()`
- **WriteTraceJobTest**: Update class `WriteHttpLogJob` → `WriteTraceJob`, property `$entry` → `$exchange`, columns `loggable_type/id` → `traceable_type/id`, model `HttpLog` → `Trace`
- **TraceFilterTest**: Update class `HttpLogFilter` → `TraceFilter`, `HttpLogEntry` → `HttpExchange`
- **TraceRedactorTest**: Update class `HttpLogRedactor` → `TraceRedactor`, `HttpLogEntry` → `HttpExchange`, config keys `log_request_body` → `store_request_body`, `log_response_body` → `store_response_body`
- **LogWriterTest**: Update `HttpLogWriter` → `TraceWriter`, `HttpLogEntry` → `HttpExchange`, log message `"HTTP Log: ..."` → `"Wiretap: ..."`
- **WiretapIntegrationTest**: Update `Wiretap::log()` → `Wiretap::trace()`, `record()` → `capture()`, `HttpLogEntry` → `HttpExchange`, `withLoggable()` → `withTraceable()`, env vars `HTTP_LOGGER_*` → `WIRETAP_*`
- **WiretapServiceProviderTest**: Update `Http::withLoggable()` → `Http::withTraceable()`, class references updated throughout, env vars renamed
- **ListenerTest**: Update `HttpLogEntry` → `HttpExchange`, `record()` → `capture()`, `$loggable` → `$traceable`
- **WiretapClientTest**: Update `withLoggable()` → `withTraceable()`, `consumeLoggable()` → `consumeTraceable()`, `$loggable` → `$traceable`
- **WiretapMiddlewareTest**: Update `$loggable` → `$traceable`, `HttpLogEntry` → `HttpExchange`
- **WiretapTest**: Update `log()` → `trace()`, `record()` → `capture()`, `HttpLogEntry` → `HttpExchange`, `HttpLogFilter` → `TraceFilter`, `HttpLogRedactor` → `TraceRedactor`

---

## General Notes

- Update **all PHPDoc blocks** in each modified file: `@param` array shapes, `@property-read` annotations, inline comments, and class-level docblocks that reference old names (`withLoggable()`, `HttpLogWriter`, `EloquentWriter`, `loggable_type`, `Wiretap::record()`, etc.).
- The `src/Pipeline/` directory does not exist yet and must be created.

---

## Breaking Changes Summary (for CHANGELOG / README)

- `HttpLogEntry` → `HttpExchange`
- `HttpLogFilter` → `Pipeline\TraceFilter`
- `HttpLogRedactor` → `Pipeline\TraceRedactor`
- `HttpLogWriter` interface → `TraceWriter`
- `EloquentWriter` → `DatabaseWriter`
- `HttpLog` model → `Trace`
- `HasHttpLogs` trait + `httpLogs()` → `HasTraces` + `traces()`
- `WriteHttpLogJob` → `WriteTraceJob`
- `LoggableScope` → `TraceableScope`
- `Wiretap::log()` → `Wiretap::trace()`
- `Wiretap::record()` → `Wiretap::capture()`
- `withLoggable()` → `withTraceable()` (both Http macro and WiretapClient)
- All `HTTP_LOGGER_*` env vars → `WIRETAP_*`
- Config keys `log_request_body` / `log_response_body` → `store_request_body` / `store_response_body`
- DB table `http_logs` → `traces`
- DB columns `loggable_type` / `loggable_id` → `traceable_type` / `traceable_id`
- This is a **v2.0.0** release

