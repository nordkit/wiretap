# Naming & Structure Review Prompt

Use this prompt with an AI assistant to review and suggest improvements to class names and file structure.

---

Please review the class names and file structure of the **nordkit/wiretap** Laravel package and suggest improvements for clarity, consistency, and convention.

## Package Overview

**nordkit/wiretap** is a HTTP traffic logger for Laravel and Guzzle. It taps into outbound (and later inbound) HTTP requests, recording the full request/response pair. The core class is `Wiretap` and the Facade is `Wiretap`.

## Current File Structure

```
src/
├── Wiretap.php                          # Core class — filters, redacts, delegates to writer
├── HttpDirection.php                    # Enum: Outbound / Inbound
├── HttpLogEntry.php                     # DTO — immutable value object for one HTTP interaction
├── FilterPipeline.php                   # Decides whether an entry should be recorded
├── RedactionPipeline.php                # Scrubs sensitive headers/body keys before persisting
│
├── Contracts/
│   └── HttpLogWriter.php                # Interface — write(HttpLogEntry): void
│
├── Guzzle/
│   ├── WiretapMiddleware.php            # Guzzle HandlerStack middleware
│   └── WiretapGuzzleClient.php          # Pre-wired Guzzle client wrapper
│
└── Laravel/
    ├── WiretapServiceProvider.php
    ├── LoggableContext.php              # Singleton — holds the current loggable model
    │
    ├── Concerns/
    │   ├── FlattenHeaders.php           # Internal trait used by listeners
    │   └── HasHttpLogs.php              # Public trait — adds httpLogs() relation to models
    │
    ├── Facades/
    │   └── Wiretap.php                  # Laravel Facade → resolves Nordkit\Wiretap\Wiretap
    │
    ├── Jobs/
    │   └── WriteHttpLogJob.php          # Queued job — persists HttpLogEntry via Eloquent
    │
    ├── Listeners/
    │   ├── RecordOutboundRequest.php    # Handles ResponseReceived event
    │   └── RecordFailedConnection.php   # Handles ConnectionFailed event
    │
    ├── Models/
    │   └── HttpLog.php                  # Eloquent model — table: http_logs
    │
    └── Writers/
        ├── EloquentWriter.php           # Dispatches WriteHttpLogJob
        └── LogWriter.php               # Writes to Laravel log channel
```

## Naming Decisions Already Made

- `Wiretap` — core class and Facade name (intentional, matches the brand)
- `HttpLog` — Eloquent model (kept, direction-agnostic, matches table `http_logs`)
- `HttpLogEntry` — DTO (kept, maps directly to `HttpLog`)
- `HttpLogWriter` — contract (kept, consistent with model/entry naming)
- `log()` — public convenience method on `Wiretap` for manual use
- `record()` — internal method called by listeners/middleware
- `write()` — storage layer method on `HttpLogWriter` implementations
- `loggable` — polymorphic relation name (kept, established Laravel convention)

## Questions to Consider

1. Are any class names inconsistent with each other or with Laravel conventions?
2. Should anything in the root `src/` be moved into a subdirectory?
3. Are `FilterPipeline` and `RedactionPipeline` well-named, or is there a better convention?
4. Is `LoggableContext` the right name for the singleton that tracks the current model?
5. Is `WiretapGuzzleClient` or `WiretapMiddleware` the better primary Guzzle integration entry point, and does the naming reflect that?
6. Does the `Concerns/` folder correctly separate internal vs public traits?
7. Are there any names that would surprise or confuse a developer using this package for the first time?

