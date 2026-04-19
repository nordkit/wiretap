<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nordkit\Wiretap\Concerns\FlattensHeaders;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Laravel\TraceableScope;
use Nordkit\Wiretap\Wiretap;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global HTTP middleware that captures every inbound request and response
 * as an HttpExchange with direction=Inbound.
 *
 * Registered automatically on the kernel when wiretap.inbound.laravel_http is true.
 *
 * Notes:
 * - Timer starts before $next() so duration covers the full controller execution.
 * - $request->getContent() is safe to call here — Symfony buffers the raw body
 *   internally, so the stream is not drained for the controller.
 * - StreamedResponse::getContent() returns false; body is stored as null in that case.
 */
class WiretapInboundMiddleware
{
    use FlattensHeaders;

    public function __construct(
        private readonly Wiretap $wiretap,
        private readonly TraceableScope $traceableScope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $timer = $this->wiretap->start();

        // Capture URL/method/headers before $next() in case the request object
        // is mutated by other middleware during the pipeline.
        $url = $request->fullUrl();
        $method = strtoupper($request->method());
        $requestHeaders = $this->flattenHeaders($request->headers->all());

        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            // Always pull from TraceableScope so stale state never leaks into
            // the next request in long-running processes (Octane / Swoole).
            $traceable = $this->traceableScope->pull();
        }

        $requestBody = $request->getContent();
        $responseBody = $response instanceof StreamedResponse
            ? null
            : $response->getContent();

        $entry = new HttpExchange(
            direction      : HttpDirection::Inbound,
            driver         : 'laravel-inbound',
            url            : $url,
            method         : $method,
            requestHeaders : $requestHeaders,
            requestBody    : $requestBody !== '' ? $requestBody : null,
            responseStatus : $response->getStatusCode(),
            responseHeaders: $this->flattenHeaders($response->headers->all()),
            responseBody   : ($responseBody !== '' && $responseBody !== false) ? $responseBody : null,
            durationMs     : $timer(),
            traceable      : $traceable,
        );

        $this->wiretap->capture($entry);

        return $response;
    }
}
