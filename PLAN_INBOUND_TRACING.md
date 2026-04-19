# Plan: Trace Incoming HTTP Traffic

Wiretap currently supports only outbound HTTP tracing via Laravel HTTP client events and a Guzzle middleware. The `HttpDirection::Inbound` case already exists but is unused. This plan adds an inbound adapter that hooks into Laravel's request/response lifecycle via middleware, capturing incoming HTTP exchanges through the same `Wiretap::capture()` pipeline.

---

## Implementation Status

| Step | Status | Notes |
|---|---|---|
| 1. Add inbound config block | ✅ Done | Full `inbound.*` block added; host/path filtering fully separated per-direction. All filtering config moved into `outbound.*` / `inbound.*` nested arrays. |
| 2. Create `WiretapInboundMiddleware` | ✅ Done | `src/Laravel/Middleware/WiretapInboundMiddleware.php` — handles streamed responses, captures full URL, method, headers, body, duration |
| 3. Register middleware in `WiretapServiceProvider` | ✅ Done | Conditionally pushed onto `Kernel` when `wiretap.inbound.laravel_http` is enabled |
| 4. Per-direction filtering in `TraceFilter` | ✅ Done | `TraceFilter` is fully direction-aware. Outbound uses `outbound.include_hosts`, `exclude_hosts`, `include_paths`, `exclude_paths`. Inbound uses `inbound.*` equivalents. `exclude_paths` takes priority over `include_paths` for both directions. |
| 5. Per-route traceable binding | ✅ Done | `WiretapTraceableMiddleware` + `wiretap.traceable` alias + `Route::macro('traceable')` fluent shorthand |
| 6. Fix binary body handling in `TraceRedactor` | ✅ Done | `processBody()` now nulls out `multipart/form-data` and `application/octet-stream` bodies before redaction. 4 new tests added to `TraceRedactorTest`. |
| 7. Tests | ✅ Done | `tests/Feature/WiretapInboundTest.php` (6 tests), `tests/Feature/WiretapTraceableMiddlewareTest.php` (5 tests), `TraceFilterTest` expanded with `include_paths` coverage |
| README & CHANGELOG | ✅ Done | Config table updated, `->traceable()` documented, v2.1.0 changelog entry added |

### Bonus fix shipped with v2.1.0
- **`DatabaseWriter` serialization bug** ✅ — The `traceable` object is now stripped from `HttpExchange` before it is passed to `WriteTraceJob`. Previously, non-serializable objects (e.g. anonymous Eloquent classes) would cause the queued job to fail silently.

---

## Steps

### 1. Add inbound config block

Add an `inbound` key to `config/wiretap.php` (mirroring the existing `outbound` block) with:
- A `laravel_http` toggle driven by a `WIRETAP_INBOUND` env var (default `false` — opt-in only).
- Its own `exclude_paths` list (see Step 4 for why host filtering is *not* duplicated here).

```php
'inbound' => [
    'laravel_http' => env('WIRETAP_INBOUND', false),
    // Regex patterns matched against the full inbound URL. Matching requests are skipped.
    // Example: ['#^/health#', '#^/metrics#']
    'exclude_paths' => [],
],
```

All body capture and redaction config (`store_request_body`, `store_response_body`, `max_body_bytes`, `redact_request_headers`, `redact_response_headers`, `redact_body_keys`) is **shared** between inbound and outbound — the semantics are identical in both directions.

---

### 2. Create `WiretapInboundMiddleware`

**File:** `src/Laravel/Middleware/WiretapInboundMiddleware.php`

- Standard Laravel middleware (`handle(Request $request, Closure $next): Response`).
- Start a timer via `Wiretap::start()` before calling `$next($request)`.
- After the response is resolved, build an `HttpExchange` with:
  - `direction: HttpDirection::Inbound`
  - `driver: 'laravel-inbound'`
  - Request URL, method, headers, and body from `$request`.
  - Response status, headers, and body from the resolved `$response`.
  - `durationMs` from the timer closure.
- Call `Wiretap::capture($entry)`.

---

### 3. Register the middleware in `WiretapServiceProvider`

In `WiretapServiceProvider::boot()`, conditionally push `WiretapInboundMiddleware` onto the global HTTP middleware stack when `wiretap.inbound.laravel_http` is enabled:

```php
if ($this->app['config']['wiretap.inbound.laravel_http']) {
    $this->app[\Illuminate\Contracts\Http\Kernel::class]
        ->pushMiddleware(WiretapInboundMiddleware::class);
}
```

---

### 4. Per-direction filtering in `TraceFilter` ✅

All filtering config is fully separated by direction. The final config shape:

```php
'outbound' => [
    'laravel_http' => ...,
    'guzzle'       => ...,
    'include_hosts' => [],   // empty = trace all outbound hosts
    'exclude_hosts' => [],   // always takes priority over include_hosts
    'include_paths' => [],   // empty = trace all paths; non-empty = only matching paths
    'exclude_paths' => [],   // always takes priority over include_paths
],
'inbound' => [
    'laravel_http'  => ...,
    'include_hosts' => [],   // matched against the Host header (your app's domain)
    'exclude_hosts' => [],
    'include_paths' => [],
    'exclude_paths' => [],
],
```

`TraceFilter` reads `HttpExchange::$direction` and selects the appropriate set of host/path rules. `exclude_paths` and `exclude_hosts` always take priority over their `include_*` counterparts for both directions.

---

### 5. Per-route traceable binding (`wiretap.traceable` middleware) ✅

**File:** `src/Laravel/Middleware/WiretapTraceableMiddleware.php` — **implemented**.

A named route middleware that scans the route's already-resolved model bindings for an instance of the given class and pushes it onto `TraceableScope`, so `WiretapInboundMiddleware` can attach it to the `HttpExchange` when it pulls the scope after `$next()` returns.

```php
// Route definition
Route::get('/orders/{order}', OrderController::class)
    ->middleware('wiretap.traceable:App\Models\Order');
```

- The `wiretap.traceable` alias is registered unconditionally in `WiretapServiceProvider::boot()` — no config flag needed.
- Works with any route that uses model binding. The middleware iterates `$request->route()->parameters()` and pushes the first instance matching the given class.
- Reuses the existing `TraceableScope` singleton — same mechanism as outbound `Http::withTraceable()`.
- Must be used on routes covered by `WiretapInboundMiddleware` (i.e. `wiretap.inbound.laravel_http` enabled).
- Add test: assert that the trace record's `traceable_type` and `traceable_id` are populated when the middleware is applied.

---

### 6. Fix binary body handling in `TraceRedactor`

`TraceRedactor::redactBodyKeys()` currently falls through and returns the **raw body** for any `Content-Type` it doesn't recognise — meaning a `multipart/form-data` file upload or `application/octet-stream` binary payload would be stored as-is. This is a gap for outbound too, but inbound makes it far more likely to hit (file uploads, webhooks with binary payloads).

**Fix:** At the top of `processBody()`, null out the body when the `Content-Type` is `multipart/form-data` or `application/octet-stream`, regardless of the `store_request_body` / `store_response_body` flag:

```php
if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/octet-stream')) {
    return null;
}
```

Add a unit test to `TraceRedactorTest` covering both content types.

---

### 7. Add tests

**File:** `tests/Feature/WiretapInboundTest.php`

- Register a test route that returns a simple JSON response.
- Make a `GET`/`POST` request via `$this->get()` / `$this->post()`.
- Assert that a trace record exists in the database with `direction = 'inbound'`, correct method, URL, status, and headers.
- Test that the middleware respects `inbound.exclude_paths` config.
- Test that shared `include_hosts` / `exclude_hosts` filters apply to inbound traces.
- Test that request/response body redaction (`redact_body_keys`) applies to inbound traces.
- Test that `multipart/form-data` and `application/octet-stream` request bodies are nulled out (via `TraceRedactorTest`).
- Test that `StreamedResponse` responses do not cause errors (body should be stored as `null`).

---

## Further Considerations

| Topic | Notes |
|---|---|
| **Timing** | `Wiretap::start()` returns a pure closure capturing `hrtime(true)` — no instance state, safe for Octane/Swoole concurrent requests. No changes needed. |
| **Body capture** | Resolved in Step 1: shared `store_request_body`, `store_response_body`, `max_body_bytes` config keys apply equally to inbound. |
| **Streamed request bodies** | Laravel buffers `$request->getContent()` internally via Symfony's `Request`, so reading it in middleware does not drain the stream for the controller. Confirm with a `POST` body integration test. |
| **Streamed responses** | `StreamedResponse::getContent()` returns `false`. Middleware must handle this: store `null` for the response body rather than casting `false` to a string. |
| **Scope** | Global middleware traces all routes by default. Shared `include_hosts`/`exclude_hosts` and per-direction `exclude_paths` handle filtering. |
| **Per-route opt-in** | Implemented via `wiretap.traceable` route middleware (Step 5). |
| **Octane / Swoole** | Timer closure is captured in the local stack frame of `handle()`, not on the instance — no state leaks between concurrent requests. The shared `TraceableScope` singleton is outbound-only and unaffected by inbound middleware. |

