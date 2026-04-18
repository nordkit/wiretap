<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(\Nordkit\Wiretap\HttpLogEntry $entry)
 * @method static \Closure startTimer()
 * @method static void log(\Nordkit\Wiretap\HttpDirection $direction, string $driver, string $url, string $method, array $requestHeaders, ?string $requestBody, ?int $responseStatus, array $responseHeaders, ?string $responseBody, ?\Closure $timer = null, ?string $errorMessage = null, ?object $loggable = null)
 *
 * @see \Nordkit\Wiretap\Wiretap
 */
class Wiretap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nordkit\Wiretap\Wiretap::class;
    }
}
