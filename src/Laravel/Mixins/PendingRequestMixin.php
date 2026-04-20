<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Mixins;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Nordkit\Wiretap\Laravel\TraceableScope;

/**
 * @mixin PendingRequest
 */
class PendingRequestMixin
{
    /**
     * @return Closure(object $traceable): PendingRequest
     */
    public function withTraceable(): Closure
    {
        return function (object $traceable): PendingRequest {
            /** @var PendingRequest $this */
            app(TraceableScope::class)->push($traceable);

            return $this;
        };
    }
}
