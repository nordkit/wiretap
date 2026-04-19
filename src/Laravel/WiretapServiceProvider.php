<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Nordkit\Wiretap\Contracts\TraceWriter;
use Nordkit\Wiretap\Guzzle\WiretapClient;
use Nordkit\Wiretap\Pipeline\TraceFilter;
use Nordkit\Wiretap\Pipeline\TraceRedactor;
use Nordkit\Wiretap\Laravel\Listeners\RecordFailedConnection;
use Nordkit\Wiretap\Laravel\Listeners\RecordOutboundRequest;
use Nordkit\Wiretap\Laravel\Models\Trace;
use Nordkit\Wiretap\Laravel\Writers\DatabaseWriter;
use Nordkit\Wiretap\Laravel\Writers\LogWriter;
use Nordkit\Wiretap\Wiretap;

class WiretapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wiretap.php', 'wiretap');

        $this->app->afterResolving(Trace::class, function (Trace $model): void {
            $model->setTable($this->app['config']->get('wiretap.table_name', 'traces'));
        });

        $this->app->singleton(TraceableScope::class);

        $this->app->singleton(TraceWriter::class, function ($app): TraceWriter {
            $config = $app['config']['wiretap'];
            $driver = (string) ($config['driver'] ?? 'database');

            if ($driver === 'log') {
                $channel = $config['log_channel'] ?? null;

                return new LogWriter(isset($channel) ? (string) $channel : null);
            }

            if ($driver === 'database') {
                $queueConfig = (array) ($config['queue'] ?? []);
                $connection = $queueConfig['connection'] ?? null;

                return new DatabaseWriter([
                    'enabled' => (bool) ($queueConfig['enabled'] ?? true),
                    'connection' => isset($connection) ? (string) $connection : null,
                    'name' => (string) ($queueConfig['name'] ?? 'logging'),
                ]);
            }

            throw new \InvalidArgumentException(
                "Unsupported wiretap driver [{$driver}]. Supported values: 'database', 'log'."
            );
        });

        $this->app->singleton(TraceFilter::class, function ($app): TraceFilter {
            $config = $app['config']['wiretap'];

            return new TraceFilter([
                'enabled' => (bool) $config['enabled'],
                'include_hosts' => (array) $config['include_hosts'],
                'exclude_hosts' => (array) $config['exclude_hosts'],
                'exclude_paths' => (array) $config['exclude_paths'],
            ]);
        });

        $this->app->singleton(TraceRedactor::class, function ($app): TraceRedactor {
            $config = $app['config']['wiretap'];

            return new TraceRedactor([
                'redact_string' => (string) ($config['redact_string'] ?? '[REDACTED]'),
                'store_request_body' => (bool) $config['store_request_body'],
                'store_response_body' => (bool) $config['store_response_body'],
                'max_body_bytes' => (int) $config['max_body_bytes'],
                'redact_request_headers' => (array) $config['redact_request_headers'],
                'redact_response_headers' => (array) $config['redact_response_headers'],
                'redact_body_keys' => (array) $config['redact_body_keys'],
            ]);
        });

        $this->app->singleton(Wiretap::class);

        $this->app->singleton(WiretapClient::class, function ($app): WiretapClient {
            return new WiretapClient($app->make(Wiretap::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/wiretap.php' => config_path('wiretap.php'),
            ], 'wiretap-config');

            $this->publishes([
                __DIR__.'/../../database/migrations/' => database_path('migrations'),
            ], 'wiretap-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app['config']['wiretap.outbound.laravel_http']) {
            Http::macro('withTraceable', function (object $traceable): PendingRequest {
                /** @var PendingRequest $this */
                app(TraceableScope::class)->push($traceable);

                return $this;
            });

            Event::listen(ResponseReceived::class, RecordOutboundRequest::class);
            Event::listen(ConnectionFailed::class, RecordFailedConnection::class);
        }
    }
}
