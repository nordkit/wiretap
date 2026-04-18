<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\Guzzle\LoggingClient;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpLogEntry;
use Nordkit\Wiretap\HttpLogFilter;
use Nordkit\Wiretap\HttpLogRedactor;
use Nordkit\Wiretap\Wiretap;

/**
 * @return array{LoggingClient, ArrayObject, MockHandler}
 */
function makeLoggingClient(array $responses = []): array
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

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);

    $client = new LoggingClient($wiretap, ['handler' => $stack]);

    return [$client, $calls, $mock];
}

it('logs successful HTTP requests automatically', function (string $method): void {
    [$client, $calls] = makeLoggingClient([
        new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'),
    ]);

    $client->{strtolower($method)}('https://api.example.com/test');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->url)->toContain('api.example.com')
        ->and($calls[0]->method)->toBe($method)
        ->and($calls[0]->responseStatus)->toBe(200)
        ->and($calls[0]->responseBody)->toBe('{"ok":true}')
        ->and($calls[0]->direction)->toBe(HttpDirection::Outbound)
        ->and($calls[0]->driver)->toBe('guzzle')
        ->and($calls[0]->loggable)->toBeNull();
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD']);

it('logs a POST request with request body', function (): void {
    [$client, $calls] = makeLoggingClient([
        new Response(201, [], '{"id":1}'),
    ]);

    $client->post('https://api.example.com/orders', ['json' => ['name' => 'test']]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('POST')
        ->and($calls[0]->responseStatus)->toBe(201);
});

it('attaches a loggable model via withLoggable()', function (): void {
    [$client, $calls] = makeLoggingClient([
        new Response(200, [], '{"synced":true}'),
    ]);

    $order = new stdClass;
    $client->withLoggable($order)->post('https://api.example.com/sync');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->loggable)->toBe($order);
});

it('consumes the loggable after the request so the next request has no loggable', function (): void {
    [$client, $calls] = makeLoggingClient([
        new Response(200, [], 'first'),
        new Response(200, [], 'second'),
    ]);

    $order = new stdClass;
    $client->withLoggable($order)->get('https://api.example.com/first');
    $client->get('https://api.example.com/second');

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->loggable)->toBe($order)
        ->and($calls[1]->loggable)->toBeNull();
});

it('logs a failed connection with errorMessage and re-throws', function (): void {
    [$client, $calls] = makeLoggingClient([
        new ConnectException('Connection refused', new Request('GET', 'https://api.example.com/fail')),
    ]);

    expect(fn () => $client->get('https://api.example.com/fail'))
        ->toThrow(ConnectException::class);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->responseStatus)->toBeNull()
        ->and($calls[0]->errorMessage)->toBe('Connection refused');
});

it('preserves the loggable on failed requests', function (): void {
    [$client, $calls] = makeLoggingClient([
        new ConnectException('Timeout', new Request('POST', 'https://api.example.com/fail')),
    ]);

    $order = new stdClass;

    try {
        $client->withLoggable($order)->post('https://api.example.com/fail');
    } catch (ConnectException) {
        // expected
    }

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->loggable)->toBe($order);
});

it('preserves response body readability after logging', function (): void {
    [$client] = makeLoggingClient([
        new Response(200, [], 'hello world'),
    ]);

    $response = $client->get('https://api.example.com/greet');

    expect((string) $response->getBody())->toBe('hello world');
});
