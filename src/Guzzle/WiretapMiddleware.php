<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Guzzle;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Nordkit\Wiretap\Concerns\FlattensHeaders;
use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;
use Nordkit\Wiretap\Wiretap;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle HandlerStack middleware.
 *
 * Usage:
 *   $stack = HandlerStack::create();
 *   $stack->push(WiretapMiddleware::make(app(Wiretap::class)));
 *   $client = new Client(['handler' => $stack]);
 */
class WiretapMiddleware
{
    use FlattensHeaders;

    /** @var callable|null */
    private $traceableResolver;

    private function __construct(private readonly Wiretap $wiretap, ?callable $traceableResolver = null)
    {
        $this->traceableResolver = $traceableResolver;
    }

    public static function make(Wiretap $wiretap, ?callable $traceableResolver = null): self
    {
        return new self($wiretap, $traceableResolver);
    }

    /**
     * Return a Guzzle handler that logs the request/response and then passes through.
     * The rejection callback re-throws the original exception after recording the error entry.
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $startNs = hrtime(true);
            $transferStats = null;
            $options['on_stats'] = function (TransferStats $stats) use (&$transferStats): void {
                $transferStats = $stats;
            };

            $traceable = $this->traceableResolver !== null ? ($this->traceableResolver)() : null;

            $requestBody = null;
            $requestStream = $request->getBody();
            if ($requestStream->isReadable()) {
                $requestBody = (string) $requestStream ?: null;
                if ($requestStream->isSeekable()) {
                    $requestStream->rewind();
                }
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $requestBody, $startNs, &$transferStats, $traceable): ResponseInterface {
                    $durationMs = $transferStats !== null
                        ? (int) round($transferStats->getTransferTime() * 1000)
                        : (int) round((hrtime(true) - $startNs) / 1_000_000);
                    $body = (string) $response->getBody();
                    $response->getBody()->rewind();
                    $this->wiretap->capture(new HttpExchange(
                        direction      : HttpDirection::Outbound,
                        driver         : 'guzzle',
                        url            : (string) $request->getUri(),
                        method         : strtoupper($request->getMethod()),
                        requestHeaders : $this->flattenHeaders($request->getHeaders()),
                        requestBody    : $requestBody,
                        responseStatus : $response->getStatusCode(),
                        responseHeaders: $this->flattenHeaders($response->getHeaders()),
                        responseBody   : $body ?: null,
                        durationMs     : $durationMs,
                        traceable       : $traceable,
                    ));

                    return $response;
                },
                function (Throwable $reason) use ($request, $requestBody, $startNs, $traceable): never {
                    $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);
                    $this->wiretap->capture(new HttpExchange(
                        direction      : HttpDirection::Outbound,
                        driver         : 'guzzle',
                        url            : (string) $request->getUri(),
                        method         : strtoupper($request->getMethod()),
                        requestHeaders : $this->flattenHeaders($request->getHeaders()),
                        requestBody    : $requestBody,
                        responseStatus : null,
                        responseHeaders: [],
                        responseBody   : null,
                        durationMs     : $durationMs,
                        errorMessage   : $reason->getMessage(),
                        traceable       : $traceable,
                    ));
                    throw $reason;
                },
            );
        };
    }
}
