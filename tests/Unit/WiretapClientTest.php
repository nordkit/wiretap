<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\Guzzle\WiretapClient;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Nordkit\Wiretap\Wiretap;

/**
 * @return array{WiretapClient, ArrayObject, MockHandler}
 */
function makeWiretapClient(array $responses = []): array
{
    $calls = new ArrayObject;
    $writer = new class($calls) implements TraceWriter
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function write(HttpExchange $entry): void
        {
            $this->calls->append($entry);
        }
    };

    $wiretap = new Wiretap(
        $writer,
        new TraceFilter(['enabled' => true, 'include_hosts' => [], 'exclude_hosts' => [], 'exclude_paths' => []]),
        new TraceRedactor([
            'store_request_body' => true, 'store_response_body' => true, 'max_body_bytes' => null,
            'redact_request_headers' => [], 'redact_response_headers' => [], 'redact_body_keys' => [],
            'redact_string' => '[REDACTED]',
        ]),
    );

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);

    $client = new WiretapClient($wiretap, ['handler' => $stack]);

    return [$client, $calls, $mock];
}

it('logs successful HTTP requests automatically', function (string $method): void {
    [$client, $calls] = makeWiretapClient([
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
        ->and($calls[0]->traceable)->toBeNull();
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD']);

it('logs a POST request with request body', function (): void {
    [$client, $calls] = makeWiretapClient([
        new Response(201, [], '{"id":1}'),
    ]);

    $client->post('https://api.example.com/orders', ['json' => ['name' => 'test']]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('POST')
        ->and($calls[0]->responseStatus)->toBe(201);
});

it('attaches a traceable model via withTraceable()', function (): void {
    [$client, $calls] = makeWiretapClient([
        new Response(200, [], '{"synced":true}'),
    ]);

    $order = new stdClass;
    $client->withTraceable($order)->post('https://api.example.com/sync');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->traceable)->toBe($order);
});

it('consumes the traceable after the request so the next request has no traceable', function (): void {
    [$client, $calls] = makeWiretapClient([
        new Response(200, [], 'first'),
        new Response(200, [], 'second'),
    ]);

    $order = new stdClass;
    $client->withTraceable($order)->get('https://api.example.com/first');
    $client->get('https://api.example.com/second');

    expect($calls)->toHaveCount(2)
        ->and($calls[0]->traceable)->toBe($order)
        ->and($calls[1]->traceable)->toBeNull();
});

it('logs a failed connection with errorMessage and re-throws', function (): void {
    [$client, $calls] = makeWiretapClient([
        new ConnectException('Connection refused', new Request('GET', 'https://api.example.com/fail')),
    ]);

    expect(fn () => $client->get('https://api.example.com/fail'))
        ->toThrow(ConnectException::class);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->responseStatus)->toBeNull()
        ->and($calls[0]->errorMessage)->toBe('Connection refused');
});

it('preserves the traceable on failed requests', function (): void {
    [$client, $calls] = makeWiretapClient([
        new ConnectException('Timeout', new Request('POST', 'https://api.example.com/fail')),
    ]);

    $order = new stdClass;

    try {
        $client->withTraceable($order)->post('https://api.example.com/fail');
    } catch (ConnectException) {
        // expected
    }

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->traceable)->toBe($order);
});

it('preserves response body readability after logging', function (): void {
    [$client] = makeWiretapClient([
        new Response(200, [], 'hello world'),
    ]);

    $response = $client->get('https://api.example.com/greet');

    expect((string) $response->getBody())->toBe('hello world');
});
