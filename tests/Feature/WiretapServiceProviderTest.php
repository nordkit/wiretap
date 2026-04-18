<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogFilter;
use Nordkit\Wiretap\HttpLogRedactor;
use Nordkit\Wiretap\Laravel\LoggableScope;
use Nordkit\Wiretap\Laravel\Writers\LogWriter;
use Nordkit\Wiretap\Wiretap;

it('resolves Wiretap from the container', function (): void {
    expect($this->app->make(Wiretap::class))->toBeInstanceOf(Wiretap::class);
});

it('resolves HttpLogWriter singleton', function (): void {
    $a = $this->app->make(HttpLogWriter::class);
    $b = $this->app->make(HttpLogWriter::class);

    expect($a)->toBeInstanceOf(HttpLogWriter::class)->and($a)->toBe($b);
});

it('resolves HttpLogFilter singleton', function (): void {
    expect($this->app->make(HttpLogFilter::class))->toBeInstanceOf(HttpLogFilter::class);
});

it('resolves HttpLogRedactor singleton', function (): void {
    expect($this->app->make(HttpLogRedactor::class))->toBeInstanceOf(HttpLogRedactor::class);
});

it('resolves LoggableScope as singleton', function (): void {
    $a = $this->app->make(LoggableScope::class);
    $b = $this->app->make(LoggableScope::class);

    expect($a)->toBe($b);
});

it('does not call writer when filter rejects entry', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements HttpLogWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpLogEntry $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new HttpLogFilter(['enabled' => false, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $wiretap->record(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200, responseHeaders: [], responseBody: null,
        durationMs: 1,
    ));

    expect($calls)->toHaveCount(0);
});

it('resolves duration from timer in log()', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements HttpLogWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpLogEntry $entry): void
        {
            $this->calls->append($entry);
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

    $timer = $wiretap->start();
    usleep(2_000);
    $wiretap->log(HttpDirection::Outbound, 'test', 'https://example.com', 'GET', [], null, 200, [], null, $timer);

    expect($calls)->toHaveCount(1)->and($calls[0]->durationMs)->toBeGreaterThan(0);
});

it('passes loggable through log() to the written entry', function (): void {
    $calls = new ArrayObject;
    $writer = new class($calls) implements HttpLogWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpLogEntry $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $loggable = new stdClass;
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
        driver: 'custom-sdk',
        url: 'https://api.example.com/sync',
        method: 'POST',
        requestHeaders: [],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: null,
        loggable: $loggable,
    );

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->loggable)->toBe($loggable);
});

it('resolves LogWriter when driver is configured as log', function (): void {
    config(['wiretap.driver' => 'log']);
    $this->app->forgetInstance(HttpLogWriter::class);

    expect($this->app->make(HttpLogWriter::class))
        ->toBeInstanceOf(LogWriter::class);
});

it('resolves LogWriter with a specific channel when configured', function (): void {
    config(['wiretap.driver' => 'log', 'wiretap.log_channel' => 'slack']);
    $this->app->forgetInstance(HttpLogWriter::class);

    $writer = $this->app->make(HttpLogWriter::class);

    expect($writer)->toBeInstanceOf(LogWriter::class);

    $property = new ReflectionProperty($writer, 'channel');
    expect($property->getValue($writer))->toBe('slack');
});

it('throws InvalidArgumentException for an unknown driver value', function (): void {
    config(['wiretap.driver' => 'mongo']);
    $this->app->forgetInstance(HttpLogWriter::class);

    expect(fn () => $this->app->make(HttpLogWriter::class))
        ->toThrow(InvalidArgumentException::class);
});

it('calls report() when debug is true and the writer throws in record()', function (): void {
    config(['wiretap.debug' => true]);

    $reported = new ArrayObject;

    $writer = new class($reported) implements HttpLogWriter
    {
        public function __construct(private readonly ArrayObject $reported) {}

        public function write(HttpLogEntry $entry): void
        {
            throw new RuntimeException('Storage failure');
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

    $wiretap->record(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    ));

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(RuntimeException::class);
});

it('calls report() when debug is true and an exception occurs inside log()', function (): void {
    config(['wiretap.debug' => true]);

    $reported = new ArrayObject;
    $writer = new class implements HttpLogWriter
    {
        public function write(HttpLogEntry $entry): void {}
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

    // Trigger an exception inside log() by passing a timer closure that throws
    $wiretap->log(
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
