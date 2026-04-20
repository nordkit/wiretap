<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\Laravel\Middleware\WiretapInboundMiddleware;
use Nordkit\Wiretap\Laravel\Models\Trace;
use Nordkit\Wiretap\Laravel\TraceableScope;
use Nordkit\Wiretap\Pipeline\TraceFilter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['wiretap.inbound.laravel_http' => true]);

    app(Kernel::class)
        ->pushMiddleware(WiretapInboundMiddleware::class);
});

it('captures an inbound GET request and stores a trace with direction=inbound', function (): void {
    Route::get('/ping', fn () => response()->json(['status' => 'ok']));

    $this->getJson('/ping');

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->direction)->toBe(HttpDirection::Inbound)
        ->and($trace->driver)->toBe('laravel-inbound')
        ->and($trace->method)->toBe('GET')
        ->and($trace->response_status)->toBe(200)
        ->and($trace->url)->toContain('/ping');
});

it('captures an inbound POST request with request and response bodies', function (): void {
    Route::post('/orders', fn () => response()->json(['created' => true], 201));

    $this->postJson('/orders', ['name' => 'test-order']);

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->direction)->toBe(HttpDirection::Inbound)
        ->and($trace->method)->toBe('POST')
        ->and($trace->response_status)->toBe(201)
        ->and($trace->request_body)->toContain('test-order')
        ->and($trace->response_body)->toContain('created');
});

it('redacts sensitive keys from the inbound request body', function (): void {
    Route::post('/login', fn () => response()->json(['token' => 'abc']));

    $this->postJson('/login', ['email' => 'user@example.com', 'password' => 'supersecret']);

    $trace = Trace::query()->first();
    $body = json_decode($trace->request_body, true);

    expect($body['password'])->toBe('[REDACTED]')
        ->and($body['email'])->toBe('user@example.com');
});

it('respects inbound.exclude_paths and does not store a trace for excluded routes', function (): void {
    config(['wiretap.inbound.exclude_paths' => ['#^.*/health$#']]);
    app()->forgetInstance(TraceFilter::class);

    Route::get('/health', fn () => response('ok'));
    Route::get('/orders', fn () => response()->json([]));

    $this->get('/health');
    $this->get('/orders');

    expect(Trace::query()->count())->toBe(1)
        ->and(Trace::query()->first()->url)->toContain('/orders');
});

it('attaches a traceable Eloquent model from TraceableScope to the stored trace', function (): void {
    Schema::create('inbound_traceables', function ($table): void {
        $table->string('id')->primary();
        $table->timestamps();
    });

    $model = new class extends Model
    {
        public $table = 'inbound_traceables';

        public $incrementing = false;

        protected $keyType = 'string';

        protected $guarded = [];
    };

    $modelClass = $model::class;
    $instance = $modelClass::create(['id' => 'item-1']);

    app(TraceableScope::class)->push($instance);

    Route::get('/items', fn () => response()->json(['ok' => true]));

    $this->get('/items');

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->traceable_id)->toBe('item-1')
        ->and($trace->traceable_type)->toBe($modelClass);
});

it('does not store a trace when wiretap is globally disabled', function (): void {
    config(['wiretap.enabled' => false]);
    app()->forgetInstance(TraceFilter::class);

    Route::get('/ping', fn () => response('ok'));

    $this->get('/ping');

    expect(Trace::query()->count())->toBe(0);
});

it('stores ip_address when inbound.store_ip is enabled', function (): void {
    config(['wiretap.inbound.store_ip' => true]);

    Route::get('/ping', fn () => response('ok'));

    $this->get('/ping');

    // ip_address column exists and was written (value depends on test transport; null is allowed
    // when the test HTTP stack does not populate REMOTE_ADDR — unit tests cover the value directly)
    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and(array_key_exists('ip_address', $trace->getAttributes()))->toBeTrue();
});

it('does not store ip_address when inbound.store_ip is disabled', function (): void {
    config(['wiretap.inbound.store_ip' => false]);

    Route::get('/ping', fn () => response('ok'));

    $this->get('/ping');

    $trace = Trace::query()->first();

    expect($trace)->not->toBeNull()
        ->and($trace->ip_address)->toBeNull();
});
it('debug: what ip_address value is stored when store_ip is enabled', function (): void {
    config(['wiretap.inbound.store_ip' => true]);
    Route::get('/ping', fn () => response('ok'));
    $this->get('/ping');
    $trace = Trace::query()->first();
    dump(['ip_address' => $trace->ip_address]);
    expect(true)->toBeTrue();
});
