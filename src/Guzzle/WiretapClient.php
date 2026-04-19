<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Nordkit\Wiretap\Wiretap;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * A pre-wired Guzzle client that automatically logs all requests via WiretapMiddleware.
 *
 * Usage (injected via the container):
 *   public function __construct(private readonly WiretapClient $http) {}
 *
 * Attach a loggable model for polymorphic association:
 *   $this->http->withLoggable($order)->post('https://api.example.com/sync', [...]);
 *
 * Note: withLoggable() is designed for sequential requests. It is consumed (reset to null)
 * after the next request is dispatched, matching the behavior of Http::withLoggable().
 */
class WiretapClient
{
    private ?object $loggable = null;

    private readonly Client $client;

    /**
     * @param  array<string, mixed>  $config  Guzzle client config options (base_uri, timeout, etc.)
     */
    public function __construct(private readonly Wiretap $wiretap, array $config = [])
    {
        $stack = isset($config['handler']) && $config['handler'] instanceof HandlerStack
            ? $config['handler']
            : HandlerStack::create($config['handler'] ?? null);

        $stack->push(WiretapMiddleware::make($this->wiretap, fn (): ?object => $this->consumeLoggable()));

        $this->client = new Client(array_merge($config, ['handler' => $stack]));
    }

    /**
     * Create a new instance with optional Guzzle config.
     *
     * @param  array<string, mixed>  $config
     */
    public static function make(Wiretap $wiretap, array $config = []): self
    {
        return new self($wiretap, $config);
    }

    /**
     * Associate an Eloquent model (or any object) with the next request log entry.
     * The loggable is consumed after the request is dispatched.
     */
    public function withLoggable(object $loggable): static
    {
        $this->loggable = $loggable;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string|UriInterface $uri = '', array $options = []): ResponseInterface
    {
        return $this->client->request($method, $uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function requestAsync(string $method, string|UriInterface $uri = '', array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->get($uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function post(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->post($uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function put(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->put($uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function patch(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->patch($uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function delete(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->delete($uri, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function head(string|UriInterface $uri, array $options = []): ResponseInterface
    {
        return $this->client->head($uri, $options);
    }

    /**
     * Access the underlying Guzzle client directly for advanced usage.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Send a PSR-7 request directly.
     *
     * @param  array<string, mixed>  $options
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->client->send($request, $options);
    }

    /**
     * Return the pending loggable and reset it to null, ensuring it is attached
     * to exactly one request before being cleared.
     */
    private function consumeLoggable(): ?object
    {
        $loggable = $this->loggable;
        $this->loggable = null;

        return $loggable;
    }
}
