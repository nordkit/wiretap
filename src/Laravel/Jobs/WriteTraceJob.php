<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Models\Trace;

/**
 * Queued job responsible for persisting a single HttpExchange via Eloquent.
 * Dispatched exclusively by DatabaseWriter; custom TraceWriter implementations
 * handle persistence directly and never dispatch this job.
 */
class WriteTraceJob implements ShouldQueue
{
    use Queueable;

    /** @var int Maximum number of times the job may be attempted */
    public int $tries = 3;

    /** @var int Number of seconds to wait before retrying the job */
    public int $backoff = 5;

    public function __construct(
        public readonly HttpExchange $exchange,
        public readonly ?string $traceableType = null,
        public readonly ?string $traceableId = null,
    ) {}

    /**
     * Laravel injects Trace via the service container, making the model
     * swappable in tests and allowing consumers to override the binding.
     * We intentionally do not resolve TraceWriter here — this job is an
     * Eloquent-specific implementation detail of DatabaseWriter. Custom
     * TraceWriter implementations handle persistence directly in write()
     * and never dispatch this job.
     */
    public function handle(Trace $trace): void
    {
        $trace->newQuery()->create([
            'direction' => $this->exchange->direction,
            'driver' => $this->exchange->driver,
            'url' => $this->exchange->url,
            'method' => $this->exchange->method,
            'request_headers' => $this->exchange->requestHeaders ?: null,
            'request_body' => $this->exchange->requestBody,
            'response_status' => $this->exchange->responseStatus,
            'response_headers' => $this->exchange->responseHeaders ?: null,
            'response_body' => $this->exchange->responseBody,
            'duration_ms' => $this->exchange->durationMs,
            'error_message' => $this->exchange->errorMessage,
            'traceable_type' => $this->traceableType,
            'traceable_id' => $this->traceableId,
        ]);
    }
}
