<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\Listeners\RecordFailedConnection;
use Nordkit\Wiretap\Laravel\Listeners\RecordOutboundRequest;
use Nordkit\Wiretap\Laravel\TraceableScope;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Nordkit\Wiretap\Wiretap;

/**
 * @return array{Wiretap, ArrayObject}
 */
function makeCapturingWiretap(): array
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

    return [$wiretap, $calls];
}

it('RecordOutboundRequest records a successful response', function (): void {
    [$wiretap, $calls] = makeCapturingWiretap();

    $psrRequest = new PsrRequest('GET', 'https://api.example.com/orders', ['Authorization' => 'Bearer token']);
    $psrResponse = new PsrResponse(200, ['Content-Type' => 'application/json'], '{"id":1}');

    $listener = new RecordOutboundRequest($wiretap, new TraceableScope);
    $listener->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->url)->toBe('https://api.example.com/orders')
        ->and($calls[0]->method)->toBe('GET')
        ->and($calls[0]->responseStatus)->toBe(200)
        ->and($calls[0]->errorMessage)->toBeNull()
        ->and($calls[0]->driver)->toBe('laravel-http');
});

it('RecordOutboundRequest sets errorMessage to null even for error responses', function (): void {
    [$wiretap, $calls] = makeCapturingWiretap();

    $psrRequest = new PsrRequest('POST', 'https://api.example.com/pay');
    $psrResponse = new PsrResponse(422, [], '{"error":"invalid"}');

    $listener = new RecordOutboundRequest($wiretap, new TraceableScope);
    $listener->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    expect($calls[0]->responseStatus)->toBe(422)
        ->and($calls[0]->errorMessage)->toBeNull();
});

it('RecordOutboundRequest attaches traceable from context', function (): void {
    [$wiretap, $calls] = makeCapturingWiretap();

    $traceable = new stdClass;
    $context = new TraceableScope;
    $context->push($traceable);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com');
    $psrResponse = new PsrResponse(200);

    $listener = new RecordOutboundRequest($wiretap, $context);
    $listener->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    expect($calls[0]->traceable)->toBe($traceable);
});

it('RecordOutboundRequest clears traceable context after use', function (): void {
    [$wiretap] = makeCapturingWiretap();

    $context = new TraceableScope;
    $context->push(new stdClass);

    $psrRequest = new PsrRequest('GET', 'https://api.example.com');
    $psrResponse = new PsrResponse(200);

    $listener = new RecordOutboundRequest($wiretap, $context);
    $listener->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    // A second request should NOT carry the traceable
    [$wiretap2, $calls2] = makeCapturingWiretap();
    $listener2 = new RecordOutboundRequest($wiretap2, $context);
    $listener2->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    expect($calls2[0]->traceable)->toBeNull();
});

it('RecordOutboundRequest sets bodies to null for empty requests and responses', function (): void {
    [$wiretap, $calls] = makeCapturingWiretap();

    $psrRequest = new PsrRequest('POST', 'https://api.example.com', [], '');
    $psrResponse = new PsrResponse(201, [], '');

    $listener = new RecordOutboundRequest($wiretap, new TraceableScope);
    $listener->handle(new ResponseReceived(new Request($psrRequest), new Response($psrResponse)));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->requestBody)->toBeNull()
        ->and($calls[0]->responseBody)->toBeNull();
});

it('RecordFailedConnection records a connection error', function (): void {
    [$wiretap, $calls] = makeCapturingWiretap();

    $psrRequest = new PsrRequest('POST', 'https://unreachable.example.com/api');
    $request = new Request($psrRequest);
    $exception = new ConnectionException('cURL error 6: Could not resolve host');

    $listener = new RecordFailedConnection($wiretap);
    $listener->handle(new ConnectionFailed($request, $exception));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->url)->toBe('https://unreachable.example.com/api')
        ->and($calls[0]->responseStatus)->toBeNull()
        ->and($calls[0]->errorMessage)->toBe('cURL error 6: Could not resolve host')
        ->and($calls[0]->driver)->toBe('laravel-http');
});
