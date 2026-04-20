# Bug: `Http::withTraceable()` throws `TypeError` when called directly on the facade

When calling `Http::withTraceable($order)` as the start of a chain, a `TypeError` is thrown:

```
Illuminate\Http\Client\Factory::{closure}(): Return value must be of type
Illuminate\Http\Client\PendingRequest, Illuminate\Http\Client\Factory returned
```

## Root cause

The macro is registered on `Http` (the `Factory`), so `$this` inside the closure is bound to the `Factory` instance — not a `PendingRequest`. The macro declares `PendingRequest` as its return type and returns `$this`, causing the type mismatch.

## Reproduction

```php
Http::withTraceable($order)->acceptJson()->post($url, $payload);
```

**Expected:** Returns a `PendingRequest` with the traceable attached.

**Actual:** `TypeError` — `Factory` returned instead of `PendingRequest`.

## Suggested fix

Register the macro on `PendingRequest` **only** — not on `Http`/`Factory`:

```php
PendingRequest::macro('withTraceable', function (object $traceable): PendingRequest {
    /** @var PendingRequest $this */
    app(TraceableScope::class)->push($traceable);
    return $this;
});
```

When `Http::withTraceable(...)` is called on the facade, Laravel's `Factory::__call()` forwards the unknown method to a new `PendingRequest` instance, so the macro on `PendingRequest` is invoked correctly. Dual registration (keeping the macro on `Factory` as well) would reintroduce the bug path and add confusion — `PendingRequest`-only is sufficient.

This allows the natural call style to work:

```php
Http::withTraceable($order)->acceptJson()->post($url, $payload);
// or mid-chain:
Http::acceptJson()->withTraceable($order)->post($url, $payload);
```

## Workaround (until fixed)

Push to `TraceableScope` directly before the request:

```php
app(TraceableScope::class)->push($order);
Http::acceptJson()->post($url, $payload);
```

