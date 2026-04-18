<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as GuzzlePsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request as LaravelHttpRequest;
use Illuminate\Support\Facades\Http;
use Nordkit\Wiretap\Laravel\Models\HttpLog;

uses(RefreshDatabase::class);

it('integrates with Laravel Http Client and logs outbound requests', function (): void {
    Http::fake([
        'github.com/*' => Http::response(['id' => 123, 'login' => 'octocat'], 200, ['X-Custom-Header' => 'test']),
    ]);

    $response = Http::withHeaders(['Authorization' => 'Bearer secret-token'])
        ->post('https://github.com/users', ['name' => 'octocat', 'password' => 'supersecret']);

    expect($response->successful())->toBeTrue();

    $log = HttpLog::query()->first();
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

    $log = HttpLog::query()->first();

    expect($log)->not->toBeNull()
        ->and($log->url)->toBe('https://fake-unresolved-domain.example.com')
        ->and($log->method)->toBe('GET')
        ->and($log->response_status)->toBeNull()
        ->and($log->error_message)->toContain('Could not resolve host');
});
