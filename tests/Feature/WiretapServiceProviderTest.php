<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\TraceableScope;
use Nordkit\Wiretap\Laravel\Writers\LogWriter;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Nordkit\Wiretap\Wiretap;

it('resolves Wiretap from the container', function (): void {
    expect($this->app->make(Wiretap::class))->toBeInstanceOf(Wiretap::class);
});

it('resolves TraceWriter singleton', function (): void {
    $a = $this->app->make(TraceWriter::class);
    $b = $this->app->make(TraceWriter::class);

    expect($a)->toBeInstanceOf(TraceWriter::class)->and($a)->toBe($b);
});

it('resolves TraceFilter singleton', function (): void {
    expect($this->app->make(TraceFilter::class))->toBeInstanceOf(TraceFilter::class);
});

it('resolves TraceRedactor singleton', function (): void {
    expect($this->app->make(TraceRedactor::class))->toBeInstanceOf(TraceRedactor::class);
});

it('resolves TraceableScope as singleton', function (): void {
    $a = $this->app->make(TraceableScope::class);
    $b = $this->app->make(TraceableScope::class);

    expect($a)->toBe($b);
});

it('does not call writer when filter rejects entry', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements TraceWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpExchange $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new TraceFilter(['enabled' => false, 'include_hosts' => [], 'exclude_hosts' => [], 'include_paths' => [], 'exclude_paths' => [], 'inbound_include_hosts' => [], 'inbound_exclude_hosts' => [], 'inbound_include_paths' => [], 'inbound_exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $wiretap->capture(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200, responseHeaders: [], responseBody: null,
        durationMs: 1,
    ));

    expect($calls)->toHaveCount(0);
});

it('resolves duration from timer in trace()', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements TraceWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpExchange $entry): void
        {
            $this->calls->append($entry);
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
    usleep(2_000);
    $wiretap->trace(HttpDirection::Outbound, 'test', 'https://example.com', 'GET', [], null, 200, [], null, $timer);

    expect($calls)->toHaveCount(1)->and($calls[0]->durationMs)->toBeGreaterThan(0);
});

it('passes traceable through trace() to the written entry', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements TraceWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpExchange $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $traceable = new stdClass;
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
        driver: 'custom-sdk',
        url: 'https://api.example.com/sync',
        method: 'POST',
        requestHeaders: [],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: null,
        traceable: $traceable,
    );

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->traceable)->toBe($traceable);
});

it('resolves LogWriter when driver is configured as log', function (): void {
    config(['wiretap.driver' => 'log']);
    $this->app->forgetInstance(TraceWriter::class);

    expect($this->app->make(TraceWriter::class))
        ->toBeInstanceOf(LogWriter::class);
});

it('resolves LogWriter with a specific channel when configured', function (): void {
    config(['wiretap.driver' => 'log', 'wiretap.log_channel' => 'slack']);
    $this->app->forgetInstance(TraceWriter::class);

    $writer = $this->app->make(TraceWriter::class);

    expect($writer)->toBeInstanceOf(LogWriter::class);

    $property = new ReflectionProperty($writer, 'channel');
    expect($property->getValue($writer))->toBe('slack');
});

it('throws InvalidArgumentException for an unknown driver value', function (): void {
    config(['wiretap.driver' => 'mongo']);
    $this->app->forgetInstance(TraceWriter::class);

    expect(fn () => $this->app->make(TraceWriter::class))
        ->toThrow(InvalidArgumentException::class);
});

it('calls report() when debug is true and the writer throws in capture()', function (): void {
    config(['wiretap.debug' => true]);

    $reported = new ArrayObject;

    $writer = new class($reported) implements TraceWriter
    {
        public function __construct(private readonly ArrayObject $reported) {}

        public function write(HttpExchange $entry): void
        {
            throw new RuntimeException('Storage failure');
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

    // Intercept report() via Laravel's exception handler
    $this->app[ExceptionHandler::class] =
        new class($reported) extends Handler
        {
            public function __construct(private readonly ArrayObject $reported)
            {
                parent::__construct(app());
            }

            public function report(Throwable $e): void
            {
                $this->reported->append($e);
            }
        };

    $wiretap->capture(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    ));

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(RuntimeException::class);
});

it('calls report() when debug is true and an exception occurs inside trace()', function (): void {
    config(['wiretap.debug' => true]);

    $reported = new ArrayObject;
    $writer = new class implements TraceWriter
    {
        public function write(HttpExchange $entry): void {}
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

    // Intercept report() via Laravel's exception handler
    $this->app[ExceptionHandler::class] =
        new class($reported) extends Handler
        {
            public function __construct(private readonly ArrayObject $reported)
            {
                parent::__construct(app());
            }

            public function report(Throwable $e): void
            {
                $this->reported->append($e);
            }
        };

    // Trigger an exception inside trace() by passing a timer closure that throws
    $wiretap->trace(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, timer: function () {
            throw new RuntimeException('Timer failed');
        },
    );

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(RuntimeException::class)
        ->and($reported[0]->getMessage())->toBe('Timer failed');
});
