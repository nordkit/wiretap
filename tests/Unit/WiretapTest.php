<?php

declare(strict_types=1);

use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Nordkit\Wiretap\Wiretap;

function makeThrowingWiretap(): array
{
    $writer = new class implements TraceWriter
    {
        public bool $called = false;

        public function write(HttpExchange $entry): void
        {
            $this->called = true;
            throw new RuntimeException('Intentional exception inside logging pipeline');
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new TraceFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'include_paths' => [], 'exclude_paths' => [], 'inbound_include_hosts' => [], 'inbound_exclude_hosts' => [], 'inbound_include_paths' => [], 'inbound_exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    return [$wiretap, $writer];
}

function makeValidEntry(): HttpExchange
{
    return new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 10
    );
}

it('swallows exceptions in capture()', function (): void {
    [$wiretap, $writer] = makeThrowingWiretap();

    // If it doesn't swallow, this would throw and fail the test
    expect(fn () => $wiretap->capture(makeValidEntry()))->not->toThrow(RuntimeException::class);

    // Verify it was actually called
    expect($writer->called)->toBeTrue();
});

it('swallows exceptions in trace()', function (): void {
    [$wiretap, $writer] = makeThrowingWiretap();

    // If it doesn't swallow, this would throw and fail the test
    expect(fn () => $wiretap->trace(
        direction: HttpDirection::Outbound,
        driver: 'test',
        url: 'https://example.com',
        method: 'GET',
        requestHeaders: [],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: null
    ))->not->toThrow(RuntimeException::class);

    // Verify it was actually called
    expect($writer->called)->toBeTrue();
});

it('accurately tracks duration with start() and stopTimer() implicitly via trace()', function (): void {
    $writer = new class implements TraceWriter
    {
        public ?HttpExchange $entry = null;

        public function write(HttpExchange $entry): void
        {
            $this->entry = $entry;
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new TraceFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'include_paths' => [], 'exclude_paths' => [], 'inbound_include_hosts' => [], 'inbound_exclude_hosts' => [], 'inbound_include_paths' => [], 'inbound_exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $timer = $wiretap->start();

    // Simulate some work... sleep for 10ms
    usleep(10000);

    $wiretap->trace(
        direction: HttpDirection::Outbound,
        driver: 'test',
        url: 'https://example.com',
        method: 'GET',
        requestHeaders: [],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: null,
        timer: $timer
    );

    expect($writer->entry)->not->toBeNull()
        ->and($writer->entry->durationMs)->toBeGreaterThanOrEqual(10);
});

it('defaults durationMs to 0 when no timer is passed to trace()', function (): void {
    $writer = new class implements TraceWriter
    {
        public ?HttpExchange $entry = null;

        public function write(HttpExchange $entry): void
        {
            $this->entry = $entry;
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new TraceFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'include_paths' => [], 'exclude_paths' => [], 'inbound_include_hosts' => [], 'inbound_exclude_hosts' => [], 'inbound_include_paths' => [], 'inbound_exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $wiretap->trace(
        direction: HttpDirection::Outbound,
        driver: 'test',
        url: 'https://example.com',
        method: 'GET',
        requestHeaders: [],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: null,
        timer: null
    );

    expect($writer->entry)->not->toBeNull()
        ->and($writer->entry->durationMs)->toBe(0);
});

it('start() returns a Closure that yields a positive integer', function (): void {
    $wiretap = new Wiretap(
        new class implements TraceWriter
        {
            public function write(HttpExchange $entry): void {}
        },
        new TraceFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'include_paths' => [], 'exclude_paths' => [], 'inbound_include_hosts' => [], 'inbound_exclude_hosts' => [], 'inbound_include_paths' => [], 'inbound_exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $timer = $wiretap->start();

    expect($timer)->toBeInstanceOf(Closure::class);

    usleep(1_000); // 1 ms

    expect($timer())->toBeInt()->toBeGreaterThan(0);
});
