<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void capture(\Nordkit\Wiretap\HttpExchange $entry)
 * @method static \Closure start()
 * @method static void trace(\Nordkit\Wiretap\HttpDirection $direction, string $driver, string $url, string $method, array $requestHeaders, ?string $requestBody, ?int $responseStatus, array $responseHeaders, ?string $responseBody, ?\Closure $timer = null, ?string $errorMessage = null, ?object $traceable = null)
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
