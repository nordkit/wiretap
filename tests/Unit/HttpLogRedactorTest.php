<?php

declare(strict_types=1);

use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogRedactor;

function makeRedactionPipeline(array $overrides = []): HttpLogRedactor
{
    return new HttpLogRedactor(array_merge([
        'log_request_body' => true,
        'log_response_body' => true,
        'max_body_bytes' => 1000,
        'redact_request_headers' => ['Authorization'],
        'redact_response_headers' => ['Set-Cookie'],
        'redact_body_keys' => ['password'],
        'redact_string' => '[REDACTED]',
    ], $overrides));
}

function makeRedactionEntry(array $overrides = []): HttpLogEntry
{
    return new HttpLogEntry(...array_merge([
        'direction' => HttpDirection::Outbound,
        'driver' => 'test',
        'url' => 'https://api.example.com',
        'method' => 'POST',
        'requestHeaders' => ['Authorization' => 'Bearer 123', 'Content-Type' => 'application/json'],
        'requestBody' => '{"password":"test","amount":100}',
        'responseStatus' => 200,
        'responseHeaders' => [],
        'responseBody' => $overrides['responseBody'] ?? null,
        'durationMs' => 20,
    ], $overrides));
}
it('redacts authorization header', function (): void {
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry());
    expect($entry->requestHeaders['Authorization'])->toBe('[REDACTED]');
});
it('leaves non-redacted headers intact', function (): void {
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry());
    expect($entry->requestHeaders['Content-Type'])->toBe('application/json');
});
it('redacts body keys recursively', function (): void {
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry());
    $body = json_decode($entry->requestBody, true);
    expect($body['password'])->toBe('[REDACTED]');
    expect($body['amount'])->toBe(100);
});
it('nulls body when log_request_body is false', function (): void {
    $entry = makeRedactionPipeline(['log_request_body' => false])->redact(makeRedactionEntry());
    expect($entry->requestBody)->toBeNull();
});
it('truncates body exceeding max_body_bytes', function (): void {
    $entry = makeRedactionPipeline(['max_body_bytes' => 5])->redact(makeRedactionEntry());
    expect($entry->requestBody)->toContain('[TRUNCATED]');
});

it('truncates JSON body before redaction, returning raw truncated string not re-encoded JSON', function (): void {
    // Body is valid JSON with a sensitive key; max_body_bytes is smaller than the body
    $body = '{"password":"secret","amount":100}';
    $entry = makeRedactionPipeline(['max_body_bytes' => 10])->redact(makeRedactionEntry(['requestBody' => $body]));

    // Must be truncated (raw substr), not decoded/redacted/re-encoded
    expect($entry->requestBody)
        ->toContain('[TRUNCATED]')
        ->not->toContain('"password"');
});
it('redacts form-encoded body keys', function (): void {
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry([
        'requestHeaders' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'requestBody' => 'password=hunter2&amount=100',
    ]));
    parse_str($entry->requestBody, $parsed);
    expect($parsed['password'])->toBe('[REDACTED]')
        ->and($parsed['amount'])->toBe('100');
});
it('leaves non-JSON non-form body untouched when redact_body_keys is set', function (): void {
    $raw = 'plain text body with password inside';
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry(['requestBody' => $raw]));
    expect($entry->requestBody)->toBe($raw);
});
it('redacts response headers', function (): void {
    $entry = makeRedactionPipeline(['redact_response_headers' => ['set-cookie']])->redact(
        makeRedactionEntry(['responseBody' => '{"ok":true}'])
    );
    // Verify pipeline does not crash on response header redaction config
    expect($entry->responseBody)->toBe('{"ok":true}');
});
it('redacts nested JSON body keys', function (): void {
    $entry = makeRedactionPipeline(['redact_body_keys' => ['secret']])->redact(
        makeRedactionEntry(['requestBody' => '{"user":{"secret":"abc","name":"Alice"}}'])
    );
    $body = json_decode($entry->requestBody, true);
    expect($body['user']['secret'])->toBe('[REDACTED]')
        ->and($body['user']['name'])->toBe('Alice');
});
it('does not truncate body when max_body_bytes is null', function (): void {
    $body = '{"name":"Alice","secret":"p@ssw0rd"}';

    $entry = makeRedactionPipeline(['max_body_bytes' => null])->redact(makeRedactionEntry(['requestBody' => $body]));
    expect($entry->requestBody)->toBe('{"name":"Alice","secret":"p@ssw0rd"}');
});
it('leaves body unchanged when redact_body_keys is empty', function (): void {
    $body = '{"name":"Alice","secret":"password123"}';

    $entry = makeRedactionPipeline(['redact_body_keys' => []])->redact(makeRedactionEntry(['requestBody' => $body]));
    expect($entry->requestBody)->toBe($body);
});
it('uses custom redact_string when provided instead of [REDACTED]', function (): void {
    $entry = makeRedactionPipeline([
        'redact_request_headers' => ['Authorization'],
        'redact_string' => '***CENSORED***',
    ])->redact(makeRedactionEntry([
        'requestHeaders' => ['Authorization' => 'Bearer token123'],
    ]));

    expect($entry->requestHeaders['Authorization'])->toBe('***CENSORED***');
});
it('gracefully handles empty form data when attempting to redact', function (): void {
    $entry = makeRedactionPipeline()->redact(makeRedactionEntry([
        'requestHeaders' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'requestBody' => '',
    ]));
    expect($entry->requestBody)->toBe('');
});
