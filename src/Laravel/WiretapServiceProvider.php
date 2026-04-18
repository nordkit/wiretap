<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Nordkit\Wiretap\Contracts\HttpLogWriter;
use Nordkit\Wiretap\Guzzle\LoggingClient;
use Nordkit\Wiretap\HttpLogFilter;
use Nordkit\Wiretap\HttpLogRedactor;
use Nordkit\Wiretap\Laravel\Listeners\RecordFailedConnection;
use Nordkit\Wiretap\Laravel\Listeners\RecordOutboundRequest;
use Nordkit\Wiretap\Laravel\Models\HttpLog;
use Nordkit\Wiretap\Laravel\Writers\EloquentWriter;
use Nordkit\Wiretap\Laravel\Writers\LogWriter;
use Nordkit\Wiretap\Wiretap;

class WiretapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wiretap.php', 'wiretap');

        $this->app->afterResolving(HttpLog::class, function (HttpLog $model): void {
            $model->setTable($this->app['config']->get('wiretap.table_name', 'http_logs'));
        });

        $this->app->singleton(LoggableScope::class);

        $this->app->singleton(HttpLogWriter::class, function ($app): HttpLogWriter {
            $config = $app['config']['wiretap'];
            $driver = (string) ($config['driver'] ?? 'database');

            if ($driver === 'log') {
                $channel = $config['log_channel'] ?? null;

                return new LogWriter(isset($channel) ? (string) $channel : null);
            }

            if ($driver === 'database') {
                $queueConfig = (array) ($config['queue'] ?? []);
                $connection = $queueConfig['connection'] ?? null;

                return new EloquentWriter([
                    'enabled' => (bool) ($queueConfig['enabled'] ?? true),
                    'connection' => isset($connection) ? (string) $connection : null,
                    'name' => (string) ($queueConfig['name'] ?? 'logging'),
                ]);
            }

            throw new \InvalidArgumentException(
                "Unsupported wiretap driver [{$driver}]. Supported values: 'database', 'log'."
            );
        });

        $this->app->singleton(HttpLogFilter::class, function ($app): HttpLogFilter {
            $config = $app['config']['wiretap'];

            return new HttpLogFilter([
                'enabled' => (bool) $config['enabled'],
                'include_hosts' => (array) $config['include_hosts'],
                'exclude_hosts' => (array) $config['exclude_hosts'],
                'exclude_paths' => (array) $config['exclude_paths'],
            ]);
        });

        $this->app->singleton(HttpLogRedactor::class, function ($app): HttpLogRedactor {
            $config = $app['config']['wiretap'];

            return new HttpLogRedactor([
                'redact_string' => (string) ($config['redact_string'] ?? '[REDACTED]'),
                'log_request_body' => (bool) $config['log_request_body'],
                'log_response_body' => (bool) $config['log_response_body'],
                'max_body_bytes' => (int) $config['max_body_bytes'],
                'redact_request_headers' => (array) $config['redact_request_headers'],
                'redact_response_headers' => (array) $config['redact_response_headers'],
                'redact_body_keys' => (array) $config['redact_body_keys'],
            ]);
        });

        $this->app->singleton(Wiretap::class);

        $this->app->singleton(LoggingClient::class, function ($app): LoggingClient {
            return new LoggingClient($app->make(Wiretap::class));
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
            Http::macro('withLoggable', function (object $loggable): PendingRequest {
                /** @var PendingRequest $this */
                app(LoggableScope::class)->push($loggable);

                return $this;
            });

            Event::listen(ResponseReceived::class, RecordOutboundRequest::class);
            Event::listen(ConnectionFailed::class, RecordFailedConnection::class);
        }
    }
}
