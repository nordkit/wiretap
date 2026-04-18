<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\Guzzle\LoggingMiddleware;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogFilter;
use Nordkit\Wiretap\HttpLogRedactor;
use Nordkit\Wiretap\Wiretap;

/**
 * @return array{Wiretap, ArrayObject}
 */
function makeGuzzleCapturingWiretap(): array
{
    $calls = new ArrayObject;
    $writer = new class($calls) implements HttpLogWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpLogEntry $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new HttpLogFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new HttpLogRedactor([
            'log_request_body' => true, 'log_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    return [$wiretap, $calls];
}

it('logs a successful Guzzle response', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}')]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    (new Client(['handler' => $stack]))->get('https://api.example.com/test');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->url)->toContain('api.example.com')
        ->and($calls[0]->method)->toBe('GET')
        ->and($calls[0]->responseStatus)->toBe(200)
        ->and($calls[0]->responseBody)->toBe('{"ok":true}')
        ->and($calls[0]->direction)->toBe(HttpDirection::Outbound)
        ->and($calls[0]->driver)->toBe('guzzle')
        ->and($calls[0]->errorMessage)->toBeNull();
});

it('logs a failed Guzzle connection with errorMessage', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([
        new ConnectException('Connection refused', new Request('POST', 'https://api.example.com/fail')),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    try {
        (new Client(['handler' => $stack]))->post('https://api.example.com/fail');
    } catch (ConnectException) {
        // expected
    }

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->responseStatus)->toBeNull()
        ->and($calls[0]->errorMessage)->toBe('Connection refused')
        ->and($calls[0]->durationMs)->toBeGreaterThanOrEqual(0);
});

it('does not swallow the original Guzzle exception', function (): void {
    [$wiretap] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([
        new ConnectException('Network error', new Request('GET', 'https://fail.example.com')),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    expect(fn () => (new Client(['handler' => $stack]))->get('https://fail.example.com'))
        ->toThrow(ConnectException::class);
});

it('preserves response body readability after logging', function (): void {
    [$wiretap] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200, [], 'hello world')]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    $response = (new Client(['handler' => $stack]))->get('https://api.example.com/greet');

    expect((string) $response->getBody())->toBe('hello world');
});

it('rewinds a seekable request body stream after logging', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200, [], '{}')]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    $stream = Utils::streamFor('{"foo":"bar"}');

    expect($stream->isSeekable())->toBeTrue();

    (new Client(['handler' => $stack]))->post('https://api.example.com/data', ['body' => $stream]);

    // Middleware should have logged the body
    expect($calls[0]->requestBody)->toBe('{"foo":"bar"}');

    // Stream must still be readable from the start after rewind
    expect((string) $stream)->toBe('{"foo":"bar"}');
});

it('handles a non-seekable request body stream gracefully', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200, [], '{}')]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    // Wrap a readable-but-non-seekable stream
    $inner = Utils::streamFor('{"hello":"world"}');
    $stream = new NoSeekStream($inner);

    expect($stream->isSeekable())->toBeFalse();

    // Should not throw
    (new Client(['handler' => $stack]))->post('https://api.example.com/data', ['body' => $stream]);

    // Body is captured (stream was readable)
    expect($calls[0]->requestBody)->toBe('{"hello":"world"}');
});

it('forces HTTP method to uppercase', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    (new Client(['handler' => $stack]))->request('get', 'https://api.example.com/data');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('GET');
});

it('flattens array header values to comma-separated strings', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([
        new Response(200, ['X-Custom-Response' => ['A', 'B', 'C']]),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    (new Client(['handler' => $stack]))->get('https://api.example.com/data', [
        'headers' => ['X-Custom-Request' => ['1', '2', '3']],
    ]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->requestHeaders['X-Custom-Request'])->toBe('1, 2, 3')
        ->and($calls[0]->responseHeaders['X-Custom-Response'])->toBe('A, B, C');
});

it('uses duration from TransferStats when provided', function (): void {
    [$wiretap, $calls] = makeGuzzleCapturingWiretap();

    $mock = new MockHandler([new Response(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(LoggingMiddleware::make($wiretap));

    (new Client(['handler' => $stack]))->get('https://api.example.com/data', [
        'transfer_time' => 0.55, // 550ms, recognized by MockHandler to populate TransferStats
    ]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->durationMs)->toBe(550);
});
