<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Contracts;

use Nordkit\Wiretap\HttpLogEntry;

/**
 * Contract for writing a captured HTTP log entry to a storage backend.
 * Bind a custom implementation in a service provider to change storage.
 */
interface HttpLogWriter
{
    public function write(HttpLogEntry $entry): void;
}
