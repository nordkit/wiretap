<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel;

/**
 * Holds the current traceable model to be associated with the next HttpExchange.
 *
 * Registered as a singleton so that the Http::withTraceable() macro and the
 * RecordOutboundRequest listener share the same instance within a single request lifecycle.
 *
 * ⚠ Concurrency note: This stores a single traceable at a time. When using
 * Http::pool() with multiple concurrent requests, only the most recently
 * pushed traceable will be attached. For concurrent use-cases, construct an
 * HttpExchange directly with the desired traceable and call Wiretap::capture().
 */
class TraceableScope
{
    private ?object $traceable = null;

    /**
     * Store a traceable model to be attached to the next HttpExchange.
     */
    public function push(object $traceable): void
    {
        $this->traceable = $traceable;
    }

    /**
     * Retrieve and clear the stored traceable model.
     * Returns null if no traceable was set, ensuring each entry only carries
     * the traceable that was explicitly associated with it.
     */
    public function pull(): ?object
    {
        $traceable = $this->traceable;
        $this->traceable = null;

        return $traceable;
    }
}
