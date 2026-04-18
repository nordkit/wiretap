<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Listeners;

use Illuminate\Http\Client\Events\ResponseReceived;
use Nordkit\Wiretap\Concerns\FlattensHeaders;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Laravel\LoggableScope;
use Nordkit\Wiretap\Wiretap;

/**
 * Listens to the Laravel HTTP client ResponseReceived event and records a log entry.
 */
class RecordOutboundRequest
{
    use FlattensHeaders;

    public function __construct(
        private readonly Wiretap $wiretap,
        private readonly LoggableScope $loggableContext,
    ) {}

    public function handle(ResponseReceived $event): void
    {
        $request = $event->request;
        $response = $event->response;
        $transferStats = $response->transferStats;
        $durationMs = $transferStats !== null
            ? (int) round($transferStats->getTransferTime() * 1000)
            : 0;
        $entry = new HttpLogEntry(
            direction      : HttpDirection::Outbound,
            driver         : 'laravel-http',
            url            : (string) $request->url(),
            method         : strtoupper($request->method()),
            requestHeaders : $this->flattenHeaders($request->headers()),
            requestBody    : $request->body() !== '' ? $request->body() : null,
            responseStatus : $response->status(),
            responseHeaders: $this->flattenHeaders($response->headers()),
            responseBody   : $response->body() !== '' ? $response->body() : null,
            durationMs     : $durationMs,
            errorMessage   : null,
            loggable       : $this->loggableContext->pull(),
        );
        $this->wiretap->record($entry);
    }
}
