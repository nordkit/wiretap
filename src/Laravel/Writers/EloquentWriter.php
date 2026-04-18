<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Writers;

use Illuminate\Database\Eloquent\Model;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Laravel\Jobs\WriteHttpLogJob;

/**
 * Default writer: dispatches a queued job to write the log entry via Eloquent.
 */
class EloquentWriter implements HttpLogWriter
{
    /** @param array{enabled: bool, connection: string|null, name: string} $queueConfig */
    public function __construct(private readonly array $queueConfig) {}

    public function write(HttpLogEntry $entry): void
    {
        $loggableType = null;
        $loggableId = null;

        if ($entry->loggable instanceof Model) {
            $loggableType = $entry->loggable->getMorphClass();
            $loggableId = (string) $entry->loggable->getKey();
        }

        $job = new WriteHttpLogJob($entry, $loggableType, $loggableId);

        if ($this->queueConfig['enabled']) {
            $job->onConnection($this->queueConfig['connection'])
                ->onQueue($this->queueConfig['name']);
            dispatch($job);
        } else {
            dispatch_sync($job);
        }
    }
}
