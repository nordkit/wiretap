<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Listeners;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Concerns\FlattensHeaders;
use Nordkit\Wiretap\Wiretap;

/**
 * Listens to the Laravel HTTP client ConnectionFailed event and records an error log entry.
 */
class RecordFailedConnection
{
    use FlattensHeaders;

    public function __construct(private readonly Wiretap $wiretap) {}

    public function handle(ConnectionFailed $event): void
    {
        $request = $event->request;
        $entry = new HttpLogEntry(
            direction      : HttpDirection::Outbound,
            driver         : 'laravel-http',
            url            : (string) $request->url(),
            method         : strtoupper($request->method()),
            requestHeaders : $this->flattenHeaders($request->headers()),
            requestBody    : $request->body() !== '' ? $request->body() : null,
            responseStatus : null,
            responseHeaders: [],
            responseBody   : null,
            durationMs     : 0,
            errorMessage   : $event->exception->getMessage(),
        );
        $this->wiretap->record($entry);
    }
}
