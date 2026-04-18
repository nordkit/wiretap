<?php

declare(strict_types=1);

use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogFilter;

function makeEntry(string $url): HttpLogEntry
{
    return new HttpLogEntry(
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

function makePipeline(array $overrides = []): HttpLogFilter
{
    return new HttpLogFilter(array_merge([
        'enabled' => true,
        'include_hosts' => [],
        'exclude_hosts' => [],
        'exclude_paths' => [],
    ], $overrides));
}

it('evaluates filter configurations correctly', function (array $config, string $url, bool $expected): void {
    $pipeline = makePipeline($config);
    expect($pipeline->shouldLog(makeEntry($url)))->toBe($expected);
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
    expect($pipeline->shouldLog(makeEntry('https://api.example.com/health')))->toBe(false);
    expect($pipeline->shouldLog(makeEntry('https://api.example.com/orders')))->toBe(true);
});

it('throws InvalidArgumentException for an invalid exclude_paths regex', function (): void {
    expect(fn () => makePipeline(['exclude_paths' => ['not-a-valid-regex']]))->toThrow(InvalidArgumentException::class);
});

it('does not throw for a valid exclude_paths regex', function (): void {
    expect(fn () => makePipeline(['exclude_paths' => ['#/health#']]))->not->toThrow(InvalidArgumentException::class);
});
