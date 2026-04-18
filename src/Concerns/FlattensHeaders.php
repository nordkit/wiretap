<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Concerns;

/**
 * Normalises a PSR-7 / Laravel HTTP header map (where values may be arrays)
 * into a flat string map suitable for storage.
 */
trait FlattensHeaders
{
    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        return array_map(
            fn ($v) => is_array($v) ? implode(', ', $v) : $v,
            $headers,
        );
    }
}
