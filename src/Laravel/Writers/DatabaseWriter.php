<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Writers;

use Illuminate\Database\Eloquent\Model;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Jobs\WriteTraceJob;

/**
 * Default writer: dispatches a queued job to write the log entry via Eloquent.
 */
class DatabaseWriter implements TraceWriter
{
    /** @param array{enabled: bool, connection: string|null, name: string} $queueConfig */
    public function __construct(private readonly array $queueConfig) {}

    public function write(HttpExchange $entry): void
    {
        $traceableType = null;
        $traceableId = null;

        if ($entry->traceable instanceof Model) {
            $traceableType = $entry->traceable->getMorphClass();
            $traceableId = (string) $entry->traceable->getKey();
        }

        // Strip the traceable object from the exchange before queueing.
        // The polymorphic relation is already captured in $traceableType / $traceableId,
        // and arbitrary objects (including anonymous classes) cannot always be serialized
        // by queue drivers. The job's handle() method never reads exchange->traceable.
        $serializableEntry = new HttpExchange(
            direction      : $entry->direction,
            driver         : $entry->driver,
            url            : $entry->url,
            method         : $entry->method,
            requestHeaders : $entry->requestHeaders,
            requestBody    : $entry->requestBody,
            responseStatus : $entry->responseStatus,
            responseHeaders: $entry->responseHeaders,
            responseBody   : $entry->responseBody,
            durationMs     : $entry->durationMs,
            errorMessage   : $entry->errorMessage,
            ipAddress      : $entry->ipAddress,
            callerClass    : $entry->callerClass,
            callerMethod   : $entry->callerMethod,
            traceable      : null,
        );

        $job = new WriteTraceJob($serializableEntry, $traceableType, $traceableId);

        if ($this->queueConfig['enabled']) {
            $job->onConnection($this->queueConfig['connection'])
                ->onQueue($this->queueConfig['name']);
            dispatch($job);
        } else {
            dispatch_sync($job);
        }
    }
}
