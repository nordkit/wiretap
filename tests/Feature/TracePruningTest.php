<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\Laravel\Models\Trace;

uses(RefreshDatabase::class);

function makePrunableTrace(string $createdAt): Trace
{
    return Trace::forceCreate([
        'direction' => HttpDirection::Inbound,
        'driver' => 'laravel-inbound',
        'url' => 'https://example.com',
        'method' => 'GET',
        'duration_ms' => 10,
        'created_at' => $createdAt,
    ]);
}

it('outputs a warning and exits cleanly when driver is log', function (): void {
    config(['wiretap.driver' => 'log']);

    $this->artisan('wiretap:prune')
        ->expectsOutputToContain('no effect')
        ->assertExitCode(0);
});

it('deletes traces older than keep_days and keeps recent ones', function (): void {
    config(['wiretap.driver' => 'database', 'wiretap.pruning.keep_days' => 30]);

    makePrunableTrace(now()->subDays(31)->toDateTimeString()); // old — should be deleted
    makePrunableTrace(now()->subDays(29)->toDateTimeString()); // recent — keep

    $this->artisan('wiretap:prune')
        ->expectsOutputToContain('Deleted 1 Wiretap trace(s) older than 30 day(s)')
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1);
});

it('respects the --days option override', function (): void {
    config(['wiretap.driver' => 'database', 'wiretap.pruning.keep_days' => 90]);

    makePrunableTrace(now()->subDays(10)->toDateTimeString()); // old when --days=7
    makePrunableTrace(now()->subDays(5)->toDateTimeString());  // recent

    $this->artisan('wiretap:prune', ['--days' => 7])
        ->expectsOutputToContain('Deleted 1 Wiretap trace(s) older than 7 day(s)')
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1);
});

it('reports zero deletions when no old traces exist', function (): void {
    config(['wiretap.driver' => 'database', 'wiretap.pruning.keep_days' => 30]);

    makePrunableTrace(now()->subDays(5)->toDateTimeString());

    $this->artisan('wiretap:prune')
        ->expectsOutputToContain('Deleted 0 Wiretap trace(s)')
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1);
});
