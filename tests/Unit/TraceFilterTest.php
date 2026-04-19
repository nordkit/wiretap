<?php

declare(strict_types=1);

use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Pipeline\TraceFilter;

function makeEntry(string $url): HttpExchange
{
    return new HttpExchange(
        direction      : HttpDirection::Outbound,
        driver         : 'laravel-http',
        url            : $url,
        method         : 'GET',
        requestHeaders : [],
        requestBody    : null,
        responseStatus : 200,
        responseHeaders: [],
        responseBody   : null,
        durationMs     : 10,
    );
}

function makePipeline(array $overrides = []): TraceFilter
{
    return new TraceFilter(array_merge([
        'enabled' => true,
        'include_hosts' => [],
        'exclude_hosts' => [],
        'include_paths' => [],
        'exclude_paths' => [],
        'inbound_include_hosts' => [],
        'inbound_exclude_hosts' => [],
        'inbound_include_paths' => [],
        'inbound_exclude_paths' => [],
    ], $overrides));
}

it('evaluates filter configurations correctly', function (array $config, string $url, bool $expected): void {
    $pipeline = makePipeline($config);
    expect($pipeline->shouldTrace(makeEntry($url)))->toBe($expected);
})->with([
    'enabled and no filters' => [['enabled' => true], 'https://api.example.com/orders', true],
    'disabled' => [['enabled' => false], 'https://api.example.com/orders', false],
    'excluded host' => [['exclude_hosts' => ['api.example.com']], 'https://api.example.com/orders', false],
    'allowed included host' => [['include_hosts' => ['allowed.com']], 'https://allowed.com/orders', true],
    'disallowed included host' => [['include_hosts' => ['allowed.com']], 'https://other.com/orders', false],
    'wildcard included host' => [['include_hosts' => ['*.example.com']], 'https://api.example.com/orders', true],
    'wildcard excluded host' => [['exclude_hosts' => ['*.internal.com']], 'https://svc.internal.com/ping', false],
    'gives precedence to exclude_hosts when both include and exclude are set' => [
        ['include_hosts' => ['api.example.com'], 'exclude_hosts' => ['api.example.com']],
        'https://api.example.com/orders',
        false,
    ],
]);

it('properly drops the path filter config', function (): void {
    $pipeline = makePipeline(['enabled' => true, 'exclude_paths' => ['#/health#']]);
    expect($pipeline->shouldTrace(makeEntry('https://api.example.com/health')))->toBe(false);
    expect($pipeline->shouldTrace(makeEntry('https://api.example.com/orders')))->toBe(true);
});

it('only traces paths matching include_paths', function (): void {
    $pipeline = makePipeline(['include_paths' => ['#^/orders#']]);
    expect($pipeline->shouldTrace(makeEntry('https://api.example.com/orders')))->toBeTrue();
    expect($pipeline->shouldTrace(makeEntry('https://api.example.com/health')))->toBeFalse();
});

it('matches anchored exclude_paths pattern against path not full url', function (): void {
    $pipeline = makePipeline(['exclude_paths' => ['#^/v2/cart#']]);
    expect($pipeline->shouldTrace(makeEntry('https://myapp.com/v2/cart/123456')))->toBeFalse();
    expect($pipeline->shouldTrace(makeEntry('https://myapp.com/v2/orders')))->toBeTrue();
});

it('matches anchored inbound_exclude_paths pattern against path not full url', function (): void {
    $pipeline = makePipeline(['inbound_exclude_paths' => ['#^/v2/cart#']]);

    $blocked = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://myapp.com/v2/cart/123456', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    $allowed = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://myapp.com/v2/orders', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    expect($pipeline->shouldTrace($blocked))->toBeFalse();
    expect($pipeline->shouldTrace($allowed))->toBeTrue();
    // Outbound is unaffected by inbound_exclude_paths
    expect($pipeline->shouldTrace(makeEntry('https://myapp.com/v2/cart/123456')))->toBeTrue();
});

it('gives precedence to exclude_paths over include_paths', function (): void {
    $pipeline = makePipeline([
        'include_paths' => ['#/orders#'],
        'exclude_paths' => ['#/orders#'],
    ]);
    expect($pipeline->shouldTrace(makeEntry('https://api.example.com/orders')))->toBeFalse();
});

it('throws InvalidArgumentException for an invalid include_paths regex', function (): void {
    expect(fn () => makePipeline(['include_paths' => ['not-a-valid-regex']]))->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException for an invalid exclude_paths regex', function (): void {
    expect(fn () => makePipeline(['exclude_paths' => ['not-a-valid-regex']]))->toThrow(InvalidArgumentException::class);
});

it('does not throw for a valid exclude_paths regex', function (): void {
    expect(fn () => makePipeline(['exclude_paths' => ['#/health#']]))->not->toThrow(InvalidArgumentException::class);
});

it('applies inbound_include_hosts only to inbound exchanges', function (): void {
    $pipeline = makePipeline(['inbound_include_hosts' => ['webhooks.myapp.com']]);

    $inbound = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://webhooks.myapp.com/stripe', method: 'POST',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    $inboundOther = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://api.myapp.com/orders', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    $outbound = makeEntry('https://api.example.com/orders');

    // Inbound to the allowed webhook host → trace
    expect($pipeline->shouldTrace($inbound))->toBeTrue();
    // Inbound to a different host → skip
    expect($pipeline->shouldTrace($inboundOther))->toBeFalse();
    // Outbound is unaffected by inbound_include_hosts → trace
    expect($pipeline->shouldTrace($outbound))->toBeTrue();
});

it('applies inbound_exclude_hosts only to inbound exchanges', function (): void {
    $pipeline = makePipeline(['inbound_exclude_hosts' => ['internal.myapp.com']]);

    $blocked = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://internal.myapp.com/ping', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    $allowed = new HttpExchange(
        direction: HttpDirection::Inbound, driver: 'laravel-inbound',
        url: 'https://api.myapp.com/orders', method: 'GET',
        requestHeaders: [], requestBody: null, responseStatus: 200,
        responseHeaders: [], responseBody: null, durationMs: 1,
    );

    expect($pipeline->shouldTrace($blocked))->toBeFalse();
    expect($pipeline->shouldTrace($allowed))->toBeTrue();
    // Outbound to the same host is unaffected → trace
    expect($pipeline->shouldTrace(makeEntry('https://internal.myapp.com/data')))->toBeTrue();
});
