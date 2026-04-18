<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Laravel\Jobs\WriteHttpLogJob;
use Nordkit\Wiretap\Laravel\Models\HttpLog;

uses(RefreshDatabase::class);

/**
 * Helper: run a WriteHttpLogJob synchronously via the container
 * so Laravel's method injection resolves HttpLog correctly.
 */
function dispatchJobSync(WriteHttpLogJob $job): void
{
    app()->call([$job, 'handle']);
}

it('writes an http log entry to the database', function (): void {
    $entry = new HttpLogEntry(
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

    dispatchJobSync(new WriteHttpLogJob($entry));

    $log = HttpLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->url)->toBe('https://api.example.com/orders')
        ->and($log->method)->toBe('POST')
        ->and($log->response_status)->toBe(201)
        ->and($log->duration_ms)->toBe(42)
        ->and($log->loggable_type)->toBeNull()
        ->and($log->loggable_id)->toBeNull();
});

it('writes loggable morph columns when entry has a loggable model', function (): void {
    $loggable = new class extends Model
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

    $entry = new HttpLogEntry(
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
        loggable       : $loggable,
    );

    dispatchJobSync(new WriteHttpLogJob($entry, $loggable->getMorphClass(), (string) $loggable->getKey()));

    $log = HttpLog::query()->first();

    expect($log->loggable_type)->toBe('order')
        ->and($log->loggable_id)->toBe('01HXYZ1234567890ABCDEFGHIJ');
});

it('dispatches a queued job when queue is enabled', function (): void {
    Queue::fake();
    config(['wiretap.queue.enabled' => true]);

    $writer = $this->app->make(HttpLogWriter::class);

    $writer->write(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://api.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5,
    ));

    Queue::assertPushed(WriteHttpLogJob::class);
});

it('writes synchronously when queue is disabled', function (): void {
    config(['wiretap.queue.enabled' => false]);

    // Re-resolve the writer so it picks up the new config
    $this->app->forgetInstance(HttpLogWriter::class);
    $writer = $this->app->make(HttpLogWriter::class);

    $writer->write(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://sync.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 3,
    ));

    expect(HttpLog::query()->where('url', 'https://sync.example.com')->exists())->toBeTrue();
});

it('resolves and extracts loggable morph keys before dispatching the job', function (): void {
    Queue::fake();
    config(['wiretap.queue.enabled' => true]);

    $writer = $this->app->make(HttpLogWriter::class);

    $loggable = new class extends Model
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

    $writer->write(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test', url: 'https://api.example.com',
        method: 'GET', requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5, loggable: $loggable
    ));

    Queue::assertPushed(WriteHttpLogJob::class, function (WriteHttpLogJob $job): bool {
        return $job->loggableType === 'dummy_model' && $job->loggableId === '12345';
    });
});
