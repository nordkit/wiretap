<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

/**
 * Immutable value object representing a single HTTP log event.
 *
 * @property-read \Nordkit\Wiretap\HttpDirection $direction
 * @property-read string $driver
 * @property-read string $url
 * @property-read string $method
 * @property-read array<string, string|list<string>> $requestHeaders
 * @property-read string|null $requestBody
 * @property-read int|null $responseStatus
 * @property-read array<string, string|list<string>> $responseHeaders
 * @property-read string|null $responseBody
 * @property-read int $durationMs
 * @property-read string|null $errorMessage
 * @property-read object|null $loggable
 */
final readonly class HttpLogEntry
{
    /**
     * @param  array<string, string|list<string>>  $requestHeaders
     * @param  array<string, string|list<string>>  $responseHeaders
     */
    public function __construct(
        public HttpDirection $direction,
        public string $driver,
        public string $url,
        public string $method,
        public array $requestHeaders,
        public ?string $requestBody,
        public ?int $responseStatus,
        public array $responseHeaders,
        public ?string $responseBody,
        public int $durationMs,
        public ?string $errorMessage = null,
        public ?object $loggable = null,
    ) {}
}
