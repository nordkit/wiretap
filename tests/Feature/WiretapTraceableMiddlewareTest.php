<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nordkit\Wiretap\Laravel\Middleware\WiretapTraceableMiddleware;
use Nordkit\Wiretap\Laravel\TraceableScope;

uses(RefreshDatabase::class);

/**
 * Inline model used only by these tests to avoid class collision with HasTracesTest.
 *
 * @property string $id
 */
class TraceableOrder extends Model
{
    protected $table = 'fake_traceable_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('fake_traceable_orders', function ($table): void {
        $table->string('id')->primary();
        $table->timestamps();
    });
});

it('pushes a matched route model binding onto TraceableScope', function (): void {
    $order = TraceableOrder::create(['id' => 'order-abc']);

    // SubstituteBindings must run before WiretapTraceableMiddleware so route
    // parameters are resolved to model instances before the middleware inspects them.
    // In real apps the web/api middleware group includes SubstituteBindings already.
    Route::get('/orders/{order}', fn (TraceableOrder $order) => response('ok'))
        ->middleware([SubstituteBindings::class, 'wiretap.traceable:'.TraceableOrder::class]);

    $this->get("/orders/{$order->id}");

    $scope = app(TraceableScope::class);

    expect($scope->pull())->toBeInstanceOf(TraceableOrder::class);
});

it('supports the ->traceable() route macro as a shorthand', function (): void {
    $order = TraceableOrder::create(['id' => 'order-macro']);

    Route::get('/orders/{order}', fn (TraceableOrder $order) => response('ok'))
        ->middleware(SubstituteBindings::class)
        ->traceable(TraceableOrder::class);

    $this->get("/orders/{$order->id}");

    expect(app(TraceableScope::class)->pull())->toBeInstanceOf(TraceableOrder::class);
});

it('does not push anything when no route parameter matches the given class', function (): void {
    Route::get('/ping', fn () => response('pong'))
        ->middleware('wiretap.traceable:'.TraceableOrder::class);

    $this->get('/ping');

    $scope = app(TraceableScope::class);

    expect($scope->pull())->toBeNull();
});

it('registers the wiretap.traceable middleware alias via the service provider', function (): void {
    $middleware = $this->app['router']->getMiddleware();

    expect($middleware)->toHaveKey('wiretap.traceable')
        ->and($middleware['wiretap.traceable'])->toBe(WiretapTraceableMiddleware::class);
});

it('clears TraceableScope after pull so consecutive requests do not bleed state', function (): void {
    $order = TraceableOrder::create(['id' => 'order-xyz']);

    Route::get('/orders/{order}', fn (TraceableOrder $order) => response('ok'))
        ->middleware([SubstituteBindings::class, 'wiretap.traceable:'.TraceableOrder::class]);

    Route::get('/ping', fn () => response('pong'))
        ->middleware('wiretap.traceable:'.TraceableOrder::class);

    $scope = app(TraceableScope::class);

    $this->get("/orders/{$order->id}");
    $scope->pull(); // simulate inbound middleware consuming the scope

    $this->get('/ping');

    expect($scope->pull())->toBeNull();
});
