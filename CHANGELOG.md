# Changelog

All notable changes to `nordkit/wiretap` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nordkit/wiretap/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/nordkit/wiretap/compare/v1.2.3...v2.0.0
[1.2.3]: https://github.com/nordkit/wiretap/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nordkit/wiretap/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nordkit/wiretap/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nordkit/wiretap/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nordkit/wiretap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nordkit/wiretap/releases/tag/v1.0.0

