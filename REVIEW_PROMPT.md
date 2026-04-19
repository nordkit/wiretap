# Review Prompt: Inbound HTTP Tracing Implementation

You are reviewing the `nordkit/wiretap` Laravel package. A new inbound HTTP tracing feature has been implemented. Your job is to verify that everything described in `PLAN_INBOUND_TRACING.md` is correctly and completely implemented, and that no edge cases have been missed.

---

## Context

- Package: `nordkit/wiretap`
- Feature branch: `main`
- Plan file: `PLAN_INBOUND_TRACING.md`
- All 107 tests pass at time of writing.

---

## Files to Review

### Source

- `src/Laravel/Middleware/WiretapInboundMiddleware.php`
- `src/Laravel/Middleware/WiretapTraceableMiddleware.php`
- `src/Laravel/WiretapServiceProvider.php`
- `src/Pipeline/TraceFilter.php`
- `src/Pipeline/TraceRedactor.php`
- `src/Laravel/Writers/DatabaseWriter.php`
- `config/wiretap.php`

### Tests

- `tests/Feature/WiretapInboundTest.php`
- `tests/Feature/WiretapTraceableMiddlewareTest.php`
- `tests/Unit/TraceFilterTest.php`
- `tests/Unit/TraceRedactorTest.php`

### Docs

- `README.md`
- `CHANGELOG.md`

---

## Review Checklist

### 1. `WiretapInboundMiddleware`
- [ ] Starts a timer before `$next($request)`
- [ ] Reads request URL, method, headers, and body after the response resolves
- [ ] Handles `StreamedResponse` — body stored as `null`, not `false`
- [ ] Pulls `TraceableScope` after `$next()` so `WiretapTraceableMiddleware` has time to push
- [ ] Calls `Wiretap::capture()` with `direction: HttpDirection::Inbound` and `driver: 'laravel-inbound'`
- [ ] Does not throw — exceptions are swallowed by `Wiretap::capture()`

### 2. `WiretapTraceableMiddleware`
- [ ] Registered as `wiretap.traceable` alias unconditionally (not gated on config)
- [ ] Scans `$request->route()->parameters()` for an instance of the given class
- [ ] Pushes the first match onto `TraceableScope`
- [ ] Does nothing (no push) if no matching parameter is found
- [ ] `Route::macro('traceable')` is registered and delegates to `->middleware('wiretap.traceable:...')`

### 3. `WiretapServiceProvider`
- [ ] `WiretapInboundMiddleware` is pushed onto the kernel only when `wiretap.inbound.laravel_http` is true
- [ ] `TraceFilter` singleton reads from `outbound.*` for outbound keys and `inbound.*` for inbound keys
- [ ] All 8 filter keys are passed: `include_hosts`, `exclude_hosts`, `include_paths`, `exclude_paths` for both directions

### 4. `TraceFilter`
- [ ] Reads `HttpExchange::$direction` to select the correct filter set
- [ ] `exclude_hosts` always takes priority over `include_hosts`
- [ ] `exclude_paths` always takes priority over `include_paths`
- [ ] `include_hosts` empty = trace all hosts
- [ ] `include_paths` empty = trace all paths
- [ ] All 4 regex lists are validated in the constructor — invalid patterns throw `InvalidArgumentException`
- [ ] Wildcard host matching uses `fnmatch()`

### 5. `TraceRedactor`
- [ ] `multipart/form-data` bodies (request and response) are nulled out before redaction
- [ ] `application/octet-stream` bodies (request and response) are nulled out before redaction
- [ ] Binary null-out happens before truncation and before redaction
- [ ] Non-binary content types are unaffected

### 6. `DatabaseWriter`
- [ ] `traceable` object is stripped from `HttpExchange` before passing to `WriteTraceJob`
- [ ] `traceable_type` and `traceable_id` are still extracted and passed separately to the job

### 7. Config (`config/wiretap.php`)
- [ ] `outbound` array contains: `laravel_http`, `guzzle`, `include_hosts`, `exclude_hosts`, `include_paths`, `exclude_paths`
- [ ] `inbound` array contains: `laravel_http`, `include_hosts`, `exclude_hosts`, `include_paths`, `exclude_paths`
- [ ] No top-level `include_hosts`, `exclude_hosts`, or `exclude_paths` keys remain
- [ ] `inbound.laravel_http` defaults to `false`
- [ ] Inline comments inside each array explain each key

### 8. Tests
- [ ] `WiretapInboundTest` covers: GET capture, POST with body, body redaction, `exclude_paths`, traceable model, globally disabled
- [ ] `WiretapTraceableMiddlewareTest` covers: pushes matched binding, `->traceable()` macro, no push when no match, alias registered, state isolation
- [ ] `TraceFilterTest` covers: `include_paths` allowlisting, `exclude_paths` wins over `include_paths`, invalid regex throws, inbound host filtering
- [ ] `TraceRedactorTest` covers: `multipart/form-data` nulled for request and response, `application/octet-stream` nulled for request and response

### 9. README
- [ ] Inbound tracing section exists under "Laravel Integration"
- [ ] Shows how to enable (`WIRETAP_INBOUND=true`)
- [ ] Shows `include_paths` / `exclude_paths` example
- [ ] Shows `include_hosts` example with note about `Host` header vs remote caller
- [ ] Config table includes all `outbound.*` and `inbound.*` keys
- [ ] `store_request_body` / `store_response_body` rows note that binary content types are always `null`
- [ ] `->traceable()` route macro is documented

### 10. CHANGELOG (v2.1.0)
- [ ] Inbound middleware added
- [ ] `outbound.*` config restructure documented (breaking change from top-level keys)
- [ ] `include_paths` for both directions documented
- [ ] `DatabaseWriter` serialization fix documented
- [ ] Binary body null-out fix documented

---

## Questions to Answer

1. Are there any cases where `WiretapInboundMiddleware` could interfere with the application response (e.g. consuming the body stream)?
2. Is the `TraceableScope` correctly isolated between requests in long-running processes (Octane/Swoole)?
3. Does `->traceable()` work correctly when the route has multiple model bindings of different types?
4. Is the outbound config restructure (`outbound.*`) a documented breaking change in the CHANGELOG?
5. Are there any content types beyond `multipart/form-data` and `application/octet-stream` that should also be nulled out?
6. Is `inbound.include_hosts` clearly documented as matching the **app's own domain** (not the remote caller)?

