<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Laravel\Models\HttpLog;

/**
 * Queued job responsible for persisting a single HttpLogEntry via Eloquent.
 * Dispatched exclusively by EloquentWriter; custom HttpLogWriter implementations
 * handle persistence directly and never dispatch this job.
 */
class WriteHttpLogJob implements ShouldQueue
{
    use Queueable;

    /** @var int  Maximum number of times the job may be attempted */
    public int $tries = 3;

    /** @var int  Number of seconds to wait before retrying the job */
    public int $backoff = 5;

    public function __construct(
        public readonly HttpLogEntry $entry,
        public readonly ?string $loggableType = null,
        public readonly ?string $loggableId = null,
    ) {}

    /**
     * Laravel injects HttpLog via the service container, making the model
     * swappable in tests and allowing consumers to override the binding.
     * We intentionally do not resolve HttpLogWriter here — this job is an
     * Eloquent-specific implementation detail of EloquentWriter. Custom
     * HttpLogWriter implementations handle persistence directly in write()
     * and never dispatch this job.
     */
    public function handle(HttpLog $httpLog): void
    {
        $httpLog->newQuery()->create([
            'direction' => $this->entry->direction,
            'driver' => $this->entry->driver,
            'url' => $this->entry->url,
            'method' => $this->entry->method,
            'request_headers' => $this->entry->requestHeaders ?: null,
            'request_body' => $this->entry->requestBody,
            'response_status' => $this->entry->responseStatus,
            'response_headers' => $this->entry->responseHeaders ?: null,
            'response_body' => $this->entry->responseBody,
            'duration_ms' => $this->entry->durationMs,
            'error_message' => $this->entry->errorMessage,
            'loggable_type' => $this->loggableType,
            'loggable_id' => $this->loggableId,
        ]);
    }
}
