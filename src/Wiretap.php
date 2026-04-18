<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

use Closure;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Throwable;

/**
 * Main entry-point for the Wiretap package.
 * End users call log() to manually record a request using raw parameters.
 * Internally, listeners and middleware call record() with a pre-built HttpLogEntry.
 */
class Wiretap
{
    public function __construct(
        private readonly HttpLogWriter $writer,
        private readonly HttpLogFilter $filter,
        private readonly HttpLogRedactor $redaction,
    ) {}

    /**
     * Start a timer and return a closure that, when called, returns the elapsed milliseconds.
     * The closure captures the start time; no state is stored on the instance, so abandoned
     * timers in long-running processes (Octane/Swoole) do not leak memory.
     */
    public function startTimer(): Closure
    {
        $start = hrtime(true);

        return fn (): int => (int) round((hrtime(true) - $start) / 1_000_000);
    }

    /**
     * Convenience method for manually logging a request from raw parameters.
     * Builds an HttpLogEntry and delegates to record().
     *
     * Use this when logging requests made outside of Laravel's HTTP Client or Guzzle
     * (e.g. raw cURL, custom SDKs). All exceptions are swallowed so this call
     * will never halt application execution.
     *
     * @param  array<string, string|list<string>>  $requestHeaders
     * @param  array<string, string|list<string>>  $responseHeaders
     *
     * @throws Throwable If debug mode is enabled and an exception occurs during logging
     */
    public function log(
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
        ?object $loggable = null,
    ): void {
        try {
            $durationMs = $timer !== null ? $timer() : 0;

            $entry = new HttpLogEntry(
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
                loggable: $loggable,
            );

            $this->record($entry);
        } catch (Throwable $e) {
            if (config('wiretap.debug', false)) {
                report($e);
            }
        }
    }

    /**
     * Evaluate, redact, and persist a pre-built HttpLogEntry.
     *
     * Called internally by listeners and middleware. Use this directly when you need
     * full control over the HttpLogEntry (e.g. concurrent requests via Http::pool()).
     * All exceptions are swallowed so recording never masks real application failures.
     *
     * @throws Throwable If debug mode is enabled and an exception occurs during recording
     */
    public function record(HttpLogEntry $entry): void
    {
        try {
            if (! $this->filter->shouldLog($entry)) {
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
