<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Concerns\HasTraces;
use Nordkit\Wiretap\Laravel\Jobs\WriteTraceJob;
use Nordkit\Wiretap\Laravel\Models\Trace;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::create('fake_orders', function ($table): void {
        $table->ulid('id')->primary();
        $table->timestamps();
    });
});

/**
 * @property string $id
 */
class FakeOrder extends Model
{
    use HasTraces;

    protected $table = 'fake_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

it('traces() relation returns associated traces', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000AAAA']);

    app()->call([new WriteTraceJob(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test',
        url: 'https://api.example.com/orders', method: 'POST',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5,
        traceable: $order,
    ), $order->getMorphClass(), (string) $order->getKey()), 'handle']);

    $logs = $order->traces;

    expect($logs)->toHaveCount(1)
        ->and($logs->first())->toBeInstanceOf(Trace::class)
        ->and($logs->first()->url)->toBe('https://api.example.com/orders');
});

it('traces() relation is empty when no traces exist', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000BBBB']);

    expect($order->traces)->toBeEmpty();
});

it('traceable() morphTo relation loads the parent model', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000CCCC']);

    app()->call([new WriteTraceJob(new HttpExchange(
        direction: HttpDirection::Outbound, driver: 'test',
        url: 'https://api.example.com', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
        traceable: $order,
    ), $order->getMorphClass(), (string) $order->getKey()), 'handle']);

    $log = Trace::query()->first();

    expect($log->traceable)->toBeInstanceOf(FakeOrder::class)
        ->and($log->traceable->id)->toBe($order->id);
});

it('traces() uses a custom model class from config', function (): void {
    // Override the model binding to a subclass
    $customModel = new class extends Trace
    {
        protected $table = 'traces';
    };

    config(['wiretap.model' => get_class($customModel)]);

    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000DDDD']);

    $relation = $order->traces();

    // The relation's related model must be the custom class
    expect($relation->getRelated())->toBeInstanceOf(get_class($customModel));
});
