<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\Laravel\Concerns\HasHttpLogs;
use Nordkit\Wiretap\Laravel\Jobs\WriteHttpLogJob;
use Nordkit\Wiretap\Laravel\Models\HttpLog;

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
    use HasHttpLogs;

    protected $table = 'fake_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

it('httpLogs() relation returns associated logs', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000AAAA']);

    app()->call([new WriteHttpLogJob(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test',
        url: 'https://api.example.com/orders', method: 'POST',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 5,
        loggable: $order,
    ), $order->getMorphClass(), (string) $order->getKey()), 'handle']);

    $logs = $order->httpLogs;

    expect($logs)->toHaveCount(1)
        ->and($logs->first())->toBeInstanceOf(HttpLog::class)
        ->and($logs->first()->url)->toBe('https://api.example.com/orders');
});

it('httpLogs() relation is empty when no logs exist', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000BBBB']);

    expect($order->httpLogs)->toBeEmpty();
});

it('loggable() morphTo relation loads the parent model', function (): void {
    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000CCCC']);

    app()->call([new WriteHttpLogJob(new HttpLogEntry(
        direction: HttpDirection::Outbound, driver: 'test',
        url: 'https://api.example.com', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
        loggable: $order,
    ), $order->getMorphClass(), (string) $order->getKey()), 'handle']);

    $log = HttpLog::query()->first();

    expect($log->loggable)->toBeInstanceOf(FakeOrder::class)
        ->and($log->loggable->id)->toBe($order->id);
});

it('httpLogs() uses a custom model class from config', function (): void {
    // Override the model binding to a subclass
    $customModel = new class extends HttpLog
    {
        protected $table = 'http_logs';
    };

    config(['wiretap.model' => get_class($customModel)]);

    $order = FakeOrder::create(['id' => '01HXYZ0000000000000000DDDD']);

    $relation = $order->httpLogs();

    // The relation's related model must be the custom class
    expect($relation->getRelated())->toBeInstanceOf(get_class($customModel));
});
