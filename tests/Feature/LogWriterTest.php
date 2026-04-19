<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Writers\LogWriter;

function makeLogEntry(array $overrides = []): HttpExchange
{
    return new HttpExchange(
        direction      : HttpDirection::Outbound,
        driver         : 'test',
        url            : $overrides['url'] ?? 'https://api.example.com/orders',
        method         : $overrides['method'] ?? 'POST',
        requestHeaders : $overrides['requestHeaders'] ?? ['Content-Type' => 'application/json'],
        requestBody    : array_key_exists('requestBody', $overrides) ? $overrides['requestBody'] : '{"foo":"bar"}',
        responseStatus : $overrides['responseStatus'] ?? 201,
        responseHeaders: $overrides['responseHeaders'] ?? [],
        responseBody   : array_key_exists('responseBody', $overrides) ? $overrides['responseBody'] : null,
        durationMs     : $overrides['durationMs'] ?? 42,
        errorMessage   : array_key_exists('errorMessage', $overrides) ? $overrides['errorMessage'] : null,
    );
}

it('writes to the default log channel when no channel is configured', function (): void {
    Log::shouldReceive('channel')->once()->with(null)->andReturnSelf();
    Log::shouldReceive('info')->once();

    (new LogWriter)->write(makeLogEntry());
});

it('writes to the configured named channel', function (): void {
    Log::shouldReceive('channel')->once()->with('slack')->andReturnSelf();
    Log::shouldReceive('info')->once();

    (new LogWriter('slack'))->write(makeLogEntry());
});

it('formats the log message as "Wiretap: {METHOD} {URL}"', function (): void {
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message) => $message === 'Wiretap: POST https://api.example.com/orders');

    (new LogWriter)->write(makeLogEntry());
});

it('strips null values from the log context', function (): void {
    $capturedContext = null;

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->withArgs(function (string $message, array $context) use (&$capturedContext): bool {
        $capturedContext = $context;

        return true;
    });

    (new LogWriter)->write(makeLogEntry([
        'requestBody' => null,
        'responseBody' => null,
        'errorMessage' => null,
    ]));

    expect($capturedContext)
        ->not->toHaveKey('request_body')
        ->not->toHaveKey('response_body')
        ->not->toHaveKey('error_message')
        ->toHaveKey('url')
        ->toHaveKey('method')
        ->toHaveKey('direction');
});

it('includes non-null values in the log context', function (): void {
    $capturedContext = null;

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->withArgs(function (string $message, array $context) use (&$capturedContext): bool {
        $capturedContext = $context;

        return true;
    });

    (new LogWriter)->write(makeLogEntry([
        'requestBody' => '{"foo":"bar"}',
        'errorMessage' => 'Service unavailable',
    ]));

    expect($capturedContext)
        ->toHaveKey('request_body')
        ->toHaveKey('error_message');
});

it('preserves zero and false values in the log context', function (): void {
    $capturedContext = null;

    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->withArgs(function (string $message, array $context) use (&$capturedContext): bool {
        $capturedContext = $context;

        return true;
    });

    (new LogWriter)->write(makeLogEntry(['durationMs' => 0]));

    expect($capturedContext)->toHaveKey('duration_ms')
        ->and($capturedContext['duration_ms'])->toBe(0);
});
