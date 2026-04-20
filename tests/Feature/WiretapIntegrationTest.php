<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as GuzzlePsrRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request as LaravelHttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\Laravel\Facades\Wiretap;
use Nordkit\Wiretap\Laravel\Models\Trace;

uses(RefreshDatabase::class);

it('integrates with Laravel Http Client and logs outbound requests', function (): void {
    Http::fake([
        'github.com/*' => Http::response(['id' => 123, 'login' => 'octocat'], 200, ['X-Custom-Header' => 'test']),
    ]);

    $response = Http::withHeaders(['Authorization' => 'Bearer secret-token'])
        ->post('https://github.com/users', ['name' => 'octocat', 'password' => 'supersecret']);

    expect($response->successful())->toBeTrue();

    $log = Trace::query()->first();
    $lowerReqHeaders = array_change_key_case($log->request_headers, CASE_LOWER);
    $lowerResHeaders = array_change_key_case($log->response_headers, CASE_LOWER);

    expect($log)->not->toBeNull()
        ->and($log->url)->toBe('https://github.com/users')
        ->and($log->method)->toBe('POST')
        ->and($log->response_status)->toBe(200)
        ->and($lowerReqHeaders)->toHaveKey('authorization', '[REDACTED]')
        ->and($lowerResHeaders)->toHaveKey('x-custom-header', 'test');

    $body = json_decode($log->request_body, true);
    expect($body)->toHaveKey('password', '[REDACTED]')
        ->and($body)->toHaveKey('name', 'octocat');

    $resBody = json_decode($log->response_body, true);
    expect($resBody)->toHaveKey('login', 'octocat');
});

it('gracefully handles and logs failed HTTP connections', function (): void {
    $psrRequest = new GuzzlePsrRequest('GET', 'https://fake-unresolved-domain.example.com');
    $request = new LaravelHttpRequest($psrRequest);
    $exception = new ConnectionException('cURL error 6: Could not resolve host');

    event(new ConnectionFailed($request, $exception));

    $log = Trace::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->url)->toBe('https://fake-unresolved-domain.example.com')
        ->and($log->method)->toBe('GET')
        ->and($log->response_status)->toBeNull()
        ->and($log->error_message)->toContain('Could not resolve host');
});

it('supports calling Http::withTraceable() directly on the facade and stores traceable morph keys', function (): void {
    Schema::create('outbound_traceables', function ($table): void {
        $table->string('id')->primary();
        $table->timestamps();
    });

    $model = new class extends Model
    {
        public $table = 'outbound_traceables';

        public $incrementing = false;

        protected $keyType = 'string';

        protected $guarded = [];
    };

    $modelClass = $model::class;
    $instance = $modelClass::create(['id' => 'order-1']);

    Http::fake([
        'github.com/*' => Http::response(['ok' => true], 200),
    ]);

    expect(function () use ($instance): void {
        Http::withTraceable($instance)
            ->post('https://github.com/users', ['name' => 'octocat']);
    })->not->toThrow(TypeError::class);

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->traceable_type)->toBe($modelClass)
        ->and($trace->traceable_id)->toBe('order-1');
});

it('persists caller_class and caller_method via Wiretap::trace()', function (): void {
    config(['wiretap.queue.enabled' => false]);

    // Re-resolve so the writer and Wiretap pick up the updated queue config.
    $this->app->forgetInstance(TraceWriter::class);
    $this->app->forgetInstance(Nordkit\Wiretap\Wiretap::class);
    Wiretap::clearResolvedInstances();

    Wiretap::trace(
        direction      : HttpDirection::Outbound,
        driver         : 'custom-sdk',
        url            : 'https://api.example.com/payments',
        method         : 'POST',
        requestHeaders : [],
        requestBody    : null,
        responseStatus : 200,
        responseHeaders: [],
        responseBody   : null,
        callerClass    : 'App\\Services\\PaymentService',
        callerMethod   : 'charge',
    );

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->caller_class)->toBe('App\\Services\\PaymentService')
        ->and($trace->caller_method)->toBe('charge');
});
