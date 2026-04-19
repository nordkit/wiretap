<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nordkit\Wiretap\Laravel\TraceableScope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware that binds a route model to the current Wiretap trace.
 *
 * Usage: ->middleware('wiretap.traceable:App\Models\Order')
 *
 * The middleware scans the route's already-resolved model bindings for an instance
 * of the given class and pushes it onto TraceableScope so the surrounding
 * WiretapInboundMiddleware can attach it to the HttpExchange.
 *
 * ⚠ This middleware must run AFTER SubstituteBindings so that route parameters
 * are already resolved to model instances. Routes registered in the web or api
 * middleware group satisfy this automatically. For bare routes (e.g. in tests),
 * add SubstituteBindings explicitly: ->middleware([SubstituteBindings::class, 'wiretap.traceable:...'])
 *
 * Must be used on routes that are also covered by WiretapInboundMiddleware
 * (i.e. wiretap.inbound.laravel_http is enabled).
 */
class WiretapTraceableMiddleware
{
    public function __construct(
        private readonly TraceableScope $traceableScope,
    ) {}

    public function handle(Request $request, Closure $next, string $traceableClass): Response
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof $traceableClass) {
                $this->traceableScope->push($parameter);
                break;
            }
        }

        return $next($request);
    }
}
