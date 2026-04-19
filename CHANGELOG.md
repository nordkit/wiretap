# Changelog

All notable changes to `nordkit/wiretap` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nordkit/wiretap/compare/v1.2.3...HEAD
[1.2.3]: https://github.com/nordkit/wiretap/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/nordkit/wiretap/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/nordkit/wiretap/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/nordkit/wiretap/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nordkit/wiretap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nordkit/wiretap/releases/tag/v1.0.0

