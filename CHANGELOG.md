# Changelog

All notable changes to `nordkit/wiretap` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- `Wiretap` facade with `log()`, `record()`, and `start()` methods.

[Unreleased]: https://github.com/nordkit/wiretap/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nordkit/wiretap/releases/tag/v1.0.0

