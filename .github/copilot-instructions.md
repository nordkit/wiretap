# Copilot Instructions

## Project

`nordkit/wiretap` is a Laravel/PHP library for tracing outbound and inbound HTTP traffic. It filters, redacts, and persists `HttpExchange` objects via a configurable pipeline.

## Language & stack

- PHP 8.3+, Laravel 11/12/13
- Tests: **Pest 4** only — never PHPUnit syntax
- Style: **Laravel Pint** (run `composer lint` to fix)

## Conventions

- All source files use `declare(strict_types=1)`
- Value objects are `final readonly` classes
- Prefer named constructor arguments in tests for readability
- No output in tests — use `expect()` assertions only

## Releasing

- Versioning follows [Semantic Versioning](https://semver.org): bug fixes = PATCH, new features = MINOR, breaking = MAJOR
- Always update `CHANGELOG.md` using [Keep a Changelog](https://keepachangelog.com) format before releasing
- Move `[Unreleased]` content into a new versioned section with today's date
- Update the comparison links at the bottom of `CHANGELOG.md`
- Commit with `chore: prepare release vX.Y.Z` and push to `main`
- **Never tag manually** — trigger the Release workflow via GitHub Actions → Release → Run workflow (enter version without `v` prefix)

## Git

- Always use `git --no-pager` to prevent output from being blocked by a pager (e.g. `git --no-pager log`, `git --no-pager diff`)
- Commit messages use the [Conventional Commits](https://www.conventionalcommits.org) format (e.g. `fix:`, `feat:`, `chore:`)
- Push directly to `main` for release preparation commits; use PRs for feature work

## Key types

| Type | Purpose |
|---|---|
| `HttpExchange` | Immutable value object for one HTTP request/response cycle |
| `HttpDirection` | Backed enum: `Outbound` / `Inbound` |
| `TraceFilter` | Pipeline step — decides whether to trace an exchange |
| `TraceRedactor` | Pipeline step — scrubs sensitive data from an exchange |
| `TraceWriter` | Interface — persists an exchange (database, log, custom) |
| `WriteTraceJob` | Queued job that writes an exchange via Eloquent |


