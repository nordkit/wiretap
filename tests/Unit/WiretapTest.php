<?php

declare(strict_types=1);

use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogFilter;
use Nordkit\Wiretap\HttpLogRedactor;
use Nordkit\Wiretap\Wiretap;

function makeThrowingWiretap(): array
{
    $writer = new class implements HttpLogWriter
    {
        public bool $called = false;

        public function write(HttpLogEntry $entry): void
        {
            $this->called = true;
            throw new RuntimeException('Intentional exception inside logging pipeline');
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new HttpLogFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    return [$wiretap, $writer];
}

function makeValidEntry(): HttpLogEntry
{
    return new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 10
    );
}

it('swallows exceptions in record()', function (): void {
    [$wiretap, $writer] = makeThrowingWiretap();

    // If it doesn't swallow, this would throw and fail the test
    expect(fn () => $wiretap->record(makeValidEntry()))->not->toThrow(RuntimeException::class);

    // Verify it was actually called
    expect($writer->called)->toBeTrue();
});

it('swallows exceptions in log()', function (): void {
    [$wiretap, $writer] = makeThrowingWiretap();

    // If it doesn't swallow, this would throw and fail the test
    expect(fn () => $wiretap->log(
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

it('accurately tracks duration with startTimer() and stopTimer() implicitly via log()', function (): void {
    $writer = new class implements HttpLogWriter
    {
        public ?HttpLogEntry $entry = null;

        public function write(HttpLogEntry $entry): void
        {
            $this->entry = $entry;
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new HttpLogFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $timer = $wiretap->startTimer();

    // Simulate some work... sleep for 10ms
    usleep(10000);

    $wiretap->log(
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

it('defaults durationMs to 0 when no timer is passed to log()', function (): void {
    $writer = new class implements HttpLogWriter
    {
        public ?HttpLogEntry $entry = null;

        public function write(HttpLogEntry $entry): void
        {
            $this->entry = $entry;
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new HttpLogFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $wiretap->log(
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

it('startTimer() returns a Closure that yields a positive integer', function (): void {
    $wiretap = new Wiretap(
        new class implements HttpLogWriter
        {
            public function write(HttpLogEntry $entry): void {}
        },
        new HttpLogFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $timer = $wiretap->startTimer();

    expect($timer)->toBeInstanceOf(Closure::class);

    usleep(1_000); // 1 ms

    expect($timer())->toBeInt()->toBeGreaterThan(0);
});
