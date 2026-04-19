<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Jobs\WriteTraceJob;
use Nordkit\Wiretap\Laravel\Models\Trace;

uses(RefreshDatabase::class);

/**
 * Helper: run a WriteTraceJob synchronously via the container
 * so Laravel's method injection resolves Trace correctly.
 */
function dispatchJobSync(WriteTraceJob $job): void
{
    app()->call([$job, 'handle']);
}

it('writes a trace entry to the database', function (): void {
    $entry = new HttpExchange(
        direction      : HttpDirection::Outbound,
        driver         : 'test',
        url            : 'https://api.example.com/orders',
        method         : 'POST',
        requestHeaders : ['Content-Type' => 'application/json'],
        requestBody    : '{"foo":"bar"}',
        responseStatus : 201,
        responseHeaders: ['Content-Type' => 'application/json'],
        responseBody   : '{"id":1}',
        durationMs     : 42,
    );

    dispatchJobSync(new WriteTraceJob($entry));

    $log = Trace::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->url)->toBe('https://api.example.com/orders')
        ->and($log->method)->toBe('POST')
        ->and($log->response_status)->toBe(201)
        ->and($log->duration_ms)->toBe(42)
        ->and($log->traceable_type)->toBeNull()
        ->and($log->traceable_id)->toBeNull();
});

it('writes traceable morph columns when entry has a traceable model', function (): void {
    $traceable = new class extends Model
    {
        protected $table = 'users';

        public function getMorphClass(): string
        {
            return 'order';
        }

        public function getKey(): mixed
        {
            return '01HXYZ1234567890ABCDEFGHIJ';
        }
    };

    $entry = new HttpExchange(
        direction      : HttpDirection::Outbound,
        driver         : 'test',
        url            : 'https://api.example.com/sync',
        method         : 'POST',
        requestHeaders : [],
        requestBody    : null,
        responseStatus : 200,
        responseHeaders: [],
        responseBody   : null,
        durationMs     : 10,
        traceable       : $traceable,
    );

    dispatchJobSync(new WriteTraceJob($entry, $traceable->getMorphClass(), (string) $traceable->getKey()));

    $log = Trace::query()->first();

    expect($log->traceable_type)->toBe('order')
        ->and($log->traceable_id)->toBe('01HXYZ1234567890ABCDEFGHIJ');
});

it('dispatches a queued job when queue is enabled', function (): void {
    Queue::fake();
    config(['wiretap.queue.enabled' => true]);

    $writer = $this->app->make(TraceWriter::class);

    $writer->write(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://api.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5,
    ));

    Queue::assertPushed(WriteTraceJob::class);
});

it('writes synchronously when queue is disabled', function (): void {
    config(['wiretap.queue.enabled' => false]);

    // Re-resolve the writer so it picks up the new config
    $this->app->forgetInstance(TraceWriter::class);
    $writer = $this->app->make(TraceWriter::class);

    $writer->write(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://sync.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 3,
    ));

    expect(Trace::query()->where('url', 'https://sync.example.com')->exists())->toBeTrue();
});

it('resolves and extracts traceable morph keys before dispatching the job', function (): void {
    Queue::fake();
    config(['wiretap.queue.enabled' => true]);

    $writer = $this->app->make(TraceWriter::class);

    $traceable = new class extends Model
    {
        public function getMorphClass(): string
        {
            return 'dummy_model';
        }

        public function getKey(): mixed
        {
            return '12345';
        }
    };

    $writer->write(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://api.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5, traceable: $traceable
    ));

    Queue::assertPushed(WriteTraceJob::class, function (WriteTraceJob $job): bool {
        return $job->traceableType === 'dummy_model' && $job->traceableId === '12345';
    });
});

it('can be serialized and unserialized without errors (enum roundtrip)', function (): void {
    $exchange = new HttpExchange(
        direction: HttpDirection::Outbound,
        driver: 'test',
        url: 'https://api.example.com/ping',
        method: 'GET',
        requestHeaders: ['Accept' => 'application/json'],
        requestBody: null,
        responseStatus: 200,
        responseHeaders: [],
        responseBody: '{"ok":true}',
        durationMs: 15,
        errorMessage: null,
    );

    $job = new WriteTraceJob($exchange, 'order', '99');

    $serialized = serialize($job);
    $restored = unserialize($serialized);

    expect($restored)->toBeInstanceOf(WriteTraceJob::class)
        ->and($restored->exchange->direction)->toBe(HttpDirection::Outbound)
        ->and($restored->exchange->url)->toBe('https://api.example.com/ping')
        ->and($restored->exchange->responseStatus)->toBe(200)
        ->and($restored->traceableType)->toBe('order')
        ->and($restored->traceableId)->toBe('99');
});

