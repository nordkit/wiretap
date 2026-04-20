<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Writers;

use Illuminate\Support\Facades\Log;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpExchange;

/**
 * Writes HTTP traces to a Laravel log channel instead of the database.
 * Useful for development or environments without a dedicated traces table.
 */
class LogWriter implements TraceWriter
{
    /**
     * @param  string|null  $channel  The log channel to write to; null uses the application default.
     */
    public function __construct(private readonly ?string $channel = null) {}

    public function write(HttpExchange $entry): void
    {
        $data = [
            'direction' => $entry->direction->value,
            'driver' => $entry->driver,
            'url' => $entry->url,
            'method' => $entry->method,
            'request_headers' => $entry->requestHeaders ?: null,
            'request_body' => $entry->requestBody,
            'response_status' => $entry->responseStatus,
            'response_headers' => $entry->responseHeaders ?: null,
            'response_body' => $entry->responseBody,
            'duration_ms' => $entry->durationMs,
            'error_message' => $entry->errorMessage,
            'ip_address' => $entry->ipAddress,
            'caller_class' => $entry->callerClass,
            'caller_method' => $entry->callerMethod,
        ];

        Log::channel($this->channel)->info("Wiretap: {$entry->method} {$entry->url}", array_filter($data, fn ($value) => $value !== null));
    }
}
