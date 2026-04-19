<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Contracts;

use Nordkit\Wiretap\HttpExchange;

/**
 * Contract for writing a captured HTTP exchange to a storage backend.
 * Bind a custom implementation in a service provider to change storage.
 */
interface TraceWriter
{
    public function write(HttpExchange $entry): void;
}
