<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel;

/**
 * Holds the current loggable model to be associated with the next HttpLogEntry.
 *
 * Registered as a singleton so that the Http::withLoggable() macro and the
 * RecordOutboundRequest listener share the same instance within a single request lifecycle.
 *
 * ⚠ Concurrency note: This stores a single loggable at a time. When using
 * Http::pool() with multiple concurrent requests, only the most recently
 * pushed loggable will be attached. For concurrent use-cases, construct an
 * HttpLogEntry directly with the desired loggable and call Wiretap::record().
 */
class LoggableScope
{
    private ?object $loggable = null;

    /**
     * Store a loggable model to be attached to the next HttpLogEntry.
     */
    public function push(object $loggable): void
    {
        $this->loggable = $loggable;
    }

    /**
     * Retrieve and clear the stored loggable model.
     * Returns null if no loggable was set, ensuring each entry only carries
     * the loggable that was explicitly associated with it.
     */
    public function pull(): ?object
    {
        $loggable = $this->loggable;
        $this->loggable = null;

        return $loggable;
    }
}
