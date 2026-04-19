# Changelog

All notable changes to `nordkit/wiretap` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.3.1] - 2026-04-19

### Fixed
- `TraceFilter` now matches `include_paths` and `exclude_paths` (both outbound and inbound) against the **path component** of the URL (e.g. `/v2/cart/123456`) instead of the full URL string. Previously, anchored patterns like `#^/v2/cart#` would never match because the stored URL begins with the scheme and host (`https://myapp.com/...`). Patterns are unchanged — existing anchored regexes now work as documented.

## [2.3.0] - 2026-04-19

### Added
- `inbound.store_ip` config key (`WIRETAP_INBOUND_STORE_IP`, default `false`) — when enabled, captures the caller's IP address on inbound traces (stored in `ip_address`, varchar 45, supports IPv4 and IPv6). Uses `$request->ip()` which respects Laravel's `TrustProxies` configuration. Disabled by default; IP addresses are personal data under GDPR and similar regulations.
- `ip_address` nullable column (varchar 45) on the `wiretap_traces` table, added via a new migration.
- `ipAddress` property on `HttpExchange` (nullable string, defaults to `null`).
- `ipAddress` parameter added to `Wiretap::trace()` (optional, defaults to `null`) — non-Laravel apps that build a custom inbound tracing layer can pass the caller's IP directly.
- `ip_address` included in `LogWriter` log context when set.

### Upgrade notes
Run `php artisan migrate` to apply the new `ip_address` column. The migration is auto-loaded by the service provider — no need to re-publish. If you have previously published the migrations, run:
```bash
php artisan vendor:publish --tag="wiretap-migrations" --force
php artisan migrate
```

## [2.2.0] - 2026-04-19

### Added
- **`wiretap:prune` Artisan command** — dedicated command to delete traces older than the configured retention window. Accepts an optional `--days` flag to override the configured value at runtime. Prints a warning and exits cleanly when `wiretap.driver` is not `database`.
- Automatic daily scheduling of `wiretap:prune` via the service provider when `pruning.enabled` is `true` and `wiretap.driver` is `database` — no entry in the application scheduler required.
- `pruning.enabled` config key (`WIRETAP_PRUNING_ENABLED`, default `false`).
- `pruning.keep_days` config key (`WIRETAP_PRUNING_KEEP_DAYS`, default `90`).

## [2.1.1] - 2026-04-19

### Fixed
- `HttpExchange` now implements `__serialize()` / `__unserialize()` so that the `HttpDirection` backed enum is stored as its scalar string value when the object is serialized to a queue payload. Previously PHP serialized the enum as a class-based object, causing `unserialize(): Class 'Nordkit\Wiretap\HttpDirection' not found` errors in queue workers when the class was not yet loaded. The `traceable` property is excluded from the serialized payload (it is resolved to morph keys before the job is dispatched and is not needed inside the job).

## [2.1.0] - 2026-04-19

### Added
- **Inbound HTTP tracing** — `Laravel\Middleware\WiretapInboundMiddleware` captures every incoming request and response as an `HttpExchange` with `direction = inbound`. Enable via `WIRETAP_INBOUND=true` (opt-in, disabled by default).
- `inbound.laravel_http` config key (`WIRETAP_INBOUND`, default `false`) — pushes `WiretapInboundMiddleware` onto the global HTTP kernel when enabled.
- `inbound.include_hosts` / `inbound.exclude_hosts` config keys — filter inbound tracing by the `Host` header of the incoming request (your app's domain). Useful for multi-domain apps or limiting tracing to a specific subdomain (e.g. `webhooks.myapp.com`).
- `inbound.include_paths` / `inbound.exclude_paths` config keys — regex allowlist and denylist for inbound URLs. `exclude_paths` always takes priority over `include_paths`.
- `outbound.include_hosts` / `outbound.include_paths` config keys — outbound-only allowlists, mirroring the new `inbound.*` structure.
- `Laravel\Middleware\WiretapTraceableMiddleware` — route middleware that binds a route model binding to the current inbound trace. The `wiretap.traceable` alias is registered automatically by the service provider.
- `->traceable(App\Models\Order::class)` route macro — fluent shorthand for `->middleware('wiretap.traceable:...')`, mirroring the outbound `Http::withTraceable()` pattern.
- `TraceFilter` is now fully direction-aware: outbound exchanges use `outbound.include_hosts`, `exclude_hosts`, `include_paths`, `exclude_paths`; inbound exchanges use their `inbound.*` equivalents.

### Changed
- README configuration reference replaced with a short descriptive overview. The published `config/wiretap.php` with its inline comments is now the canonical reference for all available options.
- **Breaking:** The outbound filter config keys `include_hosts`, `exclude_hosts`, and `exclude_paths` have been moved from the top level into an `outbound` array (`outbound.include_hosts`, `outbound.exclude_hosts`, `outbound.exclude_paths`). Run `php artisan vendor:publish --tag=wiretap-config --force` to update your published config.

### Fixed
- `DatabaseWriter` now strips the `traceable` object from `HttpExchange` before passing it to `WriteTraceJob`. This prevents serialization failures when the traceable is an object that cannot be serialized by queue drivers (e.g. anonymous classes).
- `TraceRedactor::processBody()` now stores a `[binary: filename.ext]` placeholder instead of `null` for binary content type bodies. The filename is extracted from the `Content-Disposition` header or the multipart part header when available; falls back to `[binary: content/type]` when no filename can be determined. Previously only `multipart/form-data` and `application/octet-stream` were handled at all, causing images, PDFs, and other binary files under `max_body_bytes` to be stored as raw binary data in the database. Now covers: `image/*`, `video/*`, `audio/*`, `multipart/form-data`, `application/octet-stream`, `application/pdf`, `application/zip`, `application/gzip`, and `application/x-tar`.

## [2.0.0] - 2026-04-19

### Added
- `HttpExchange` class (replaces `HttpLogEntry`) — represents the transient HTTP request/response cycle.
- `Pipeline\TraceFilter` class (replaces `HttpLogFilter`) with `shouldTrace(HttpExchange)` method.
- `Pipeline\TraceRedactor` class (replaces `HttpLogRedactor`) with `redact(HttpExchange)` method.
- `Contracts\TraceWriter` interface (replaces `HttpLogWriter`) with `write(HttpExchange)` method.
- `Laravel\Models\Trace` model (replaces `HttpLog`) backed by the `wiretap_traces` table.
- `Laravel\Concerns\HasTraces` trait (replaces `HasHttpLogs`) with `traces()` relation method.
- `Laravel\Jobs\WriteTraceJob` (replaces `WriteHttpLogJob`).
- `Laravel\Writers\DatabaseWriter` (replaces `EloquentWriter`).
- `Laravel\TraceableScope` (replaces `LoggableScope`).
- `Http::withTraceable()` macro (replaces `Http::withLoggable()`).
- `WiretapClient::withTraceable()` method (replaces `withLoggable()`).
- `Wiretap::trace()` public API method (replaces `log()`).
- `Wiretap::capture()` internal pipeline method (replaces `record()`).

### Changed
- Database table renamed from `http_logs` to `wiretap_traces`.
- Polymorphic columns renamed from `loggable_type` / `loggable_id` to `traceable_type` / `traceable_id`.
- Config key `log_request_body` renamed to `store_request_body`.
- Config key `log_response_body` renamed to `store_response_body`.
- Config key `model` default updated to `Nordkit\Wiretap\Laravel\Models\Trace`.
- All `HTTP_LOGGER_*` environment variables renamed to `WIRETAP_*` (e.g. `HTTP_LOGGER_ENABLED` → `WIRETAP_ENABLED`, `HTTP_LOGGER_LOG_REQUEST_BODY` → `WIRETAP_STORE_REQUEST_BODY`).
- `LogWriter` log message prefix changed from `"HTTP Log: ..."` to `"Wiretap: ..."`.
- Updated README to reflect all v2.0.0 renames.

### Removed
- `HttpLogEntry` (replaced by `HttpExchange`).
- `HttpLogFilter` (replaced by `Pipeline\TraceFilter`).
- `HttpLogRedactor` (replaced by `Pipeline\TraceRedactor`).
- `Contracts\HttpLogWriter` interface (replaced by `Contracts\TraceWriter`).
- `Laravel\Models\HttpLog` model (replaced by `Laravel\Models\Trace`).
- `Laravel\Concerns\HasHttpLogs` trait (replaced by `Laravel\Concerns\HasTraces`).
- `Laravel\Jobs\WriteHttpLogJob` (replaced by `Laravel\Jobs\WriteTraceJob`).
- `Laravel\Writers\EloquentWriter` (replaced by `Laravel\Writers\DatabaseWriter`).
- `Laravel\LoggableScope` (replaced by `Laravel\TraceableScope`).

## [1.2.3] - 2026-04-19

### Fixed
- Removed `use Nordkit\Wiretap\Laravel\Models\HttpLog` import from the published config file, which caused an "undefined namespace" error in consuming projects. The `model` key now uses a plain class-name string instead of `HttpLog::class`.
- Removed extra blank line in `config/wiretap.php` to satisfy Pint's `no_extra_blank_lines` rule.

### Changed
- Updated README to reflect the new plain class-name string default for the `model` config key.

## [1.2.2] - 2026-04-19

### Fixed
- Removed extra blank line in `config/wiretap.php` to satisfy Pint's `no_extra_blank_lines` rule.

## [1.2.1] - 2026-04-19

### Fixed
- Removed `use Nordkit\Wiretap\Laravel\Models\HttpLog` import from the published config file, which caused an "undefined namespace" error in consuming projects. The `model` key now uses a plain class-name string instead of `HttpLog::class`.

## [1.2.0] - 2026-04-19

### Changed
- Renamed `Guzzle\LoggingClient` to `Guzzle\WiretapClient`.
- Renamed `Guzzle\LoggingMiddleware` to `Guzzle\WiretapMiddleware`.

## [1.1.0] - 2026-04-18

### Changed
- Renamed `startTimer()` to `start()` on `Wiretap` and the `Wiretap` Facade.

## [1.0.0] - 2026-04-18

### Added
- Initial release of `nordkit/wiretap`.
- Outbound HTTP logging for Laravel HTTP Client and Guzzle.
- Configurable `HttpLogFilter` pipeline (include/exclude hosts and paths).
- Configurable `HttpLogRedactor` pipeline (headers, JSON body keys, body truncation).
- `EloquentWriter` (queued via `WriteHttpLogJob`) and `LogWriter` backends.
- `Http::withLoggable()` macro for polymorphic model association.
- `HasHttpLogs` trait for retrieving associated logs from Eloquent models.
- `LoggingClient` for a pre-wired Guzzle wrapper.
- `LoggingMiddleware` for raw Guzzle `HandlerStack` integration.
- `Wiretap` facade with `log()`, `record()`, and `startTimer()` methods.

[Unreleased]: https://github.com/nordkit/wiretap/compare/v2.3.1...HEAD
[2.3.1]: https://github.com/nordkit/wiretap/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/nordkit/wiretap/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/nordkit/wiretap/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/nordkit/wiretap/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/nordkit/wiretap/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/nordkit/wiretap/compare/v1.2.3...v2.0.0
[1.2.3]: https://github.com/nordkit/wiretap/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nordkit/wiretap/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nordkit/wiretap/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nordkit/wiretap/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nordkit/wiretap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nordkit/wiretap/releases/tag/v1.0.0

