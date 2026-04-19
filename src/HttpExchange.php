<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

/**
 * Immutable value object representing a single HTTP exchange event.
 *
 * @property-read HttpDirection $direction
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
 * @property-read object|null $traceable
 */
final readonly class HttpExchange
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
        public ?object $traceable = null,
    ) {}

    /**
     * Serialize to a queue-safe array, converting the HttpDirection enum to its
     * scalar value and dropping the non-serializable traceable object (it is
     * resolved to morph keys before the job is dispatched and never needed again
     * inside the job payload).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'direction' => $this->direction->value,
            'driver' => $this->driver,
            'url' => $this->url,
            'method' => $this->method,
            'requestHeaders' => $this->requestHeaders,
            'requestBody' => $this->requestBody,
            'responseStatus' => $this->responseStatus,
            'responseHeaders' => $this->responseHeaders,
            'responseBody' => $this->responseBody,
            'durationMs' => $this->durationMs,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        // readonly properties must be initialised via Closure binding on PHP 8.1+
        $init = function (array $data): void {
            $this->direction = HttpDirection::from($data['direction']);
            $this->driver = $data['driver'];
            $this->url = $data['url'];
            $this->method = $data['method'];
            $this->requestHeaders = $data['requestHeaders'];
            $this->requestBody = $data['requestBody'];
            $this->responseStatus = $data['responseStatus'];
            $this->responseHeaders = $data['responseHeaders'];
            $this->responseBody = $data['responseBody'];
            $this->durationMs = $data['durationMs'];
            $this->errorMessage = $data['errorMessage'];
            $this->traceable = null;
        };

        $init->bindTo($this, static::class)($data);
    }
}
