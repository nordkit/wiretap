<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

use Closure;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Throwable;

/**
 * Main entry-point for the Wiretap package.
 * End users call trace() to manually capture a request using raw parameters.
 * Internally, listeners and middleware call capture() with a pre-built HttpExchange.
 */
class Wiretap
{
    public function __construct(
        private readonly TraceWriter $writer,
        private readonly TraceFilter $filter,
        private readonly TraceRedactor $redaction,
    ) {}

    /**
     * Start a timer and return a closure that, when called, returns the elapsed milliseconds.
     * The closure captures the start time; no state is stored on the instance, so abandoned
     * timers in long-running processes (Octane/Swoole) do not leak memory.
     */
    public function start(): Closure
    {
        $start = hrtime(true);

        return fn (): int => (int) round((hrtime(true) - $start) / 1_000_000);
    }

    /**
     * Convenience method for manually tracing a request from raw parameters.
     * Builds an HttpExchange and delegates to capture().
     *
     * Use this when tracing requests made outside of Laravel's HTTP Client or Guzzle
     * (e.g. raw cURL, custom SDKs). All exceptions are swallowed so this call
     * will never halt application execution.
     *
     * @param  array<string, string|list<string>>  $requestHeaders
     * @param  array<string, string|list<string>>  $responseHeaders
     *
     * @throws Throwable If debug mode is enabled and an exception occurs during tracing
     */
    public function trace(
        HttpDirection $direction,
        string $driver,
        string $url,
        string $method,
        array $requestHeaders,
        ?string $requestBody,
        ?int $responseStatus,
        array $responseHeaders,
        ?string $responseBody,
        ?Closure $timer = null,
        ?string $errorMessage = null,
        ?object $traceable = null,
    ): void {
        try {
            $durationMs = $timer !== null ? $timer() : 0;

            $entry = new HttpExchange(
                direction: $direction,
                driver: $driver,
                url: $url,
                method: $method,
                requestHeaders: $requestHeaders,
                requestBody: $requestBody,
                responseStatus: $responseStatus,
                responseHeaders: $responseHeaders,
                responseBody: $responseBody,
                durationMs: $durationMs,
                errorMessage: $errorMessage,
                traceable: $traceable,
            );

            $this->capture($entry);
        } catch (Throwable $e) {
            if (config('wiretap.debug', false)) {
                report($e);
            }
        }
    }

    /**
     * Evaluate, redact, and persist a pre-built HttpExchange.
     *
     * Called internally by listeners and middleware. Use this directly when you need
     * full control over the HttpExchange (e.g. concurrent requests via Http::pool()).
     * All exceptions are swallowed so capture never masks real application failures.
     *
     * @throws Throwable If debug mode is enabled and an exception occurs during capture
     */
    public function capture(HttpExchange $entry): void
    {
        try {
            if (! $this->filter->shouldTrace($entry)) {
                return;
            }

            $redacted = $this->redaction->redact($entry);
            $this->writer->write($redacted);
        } catch (Throwable $e) {
            if (config('wiretap.debug', false)) {
                report($e);
            }
        }
    }
}
