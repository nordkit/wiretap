<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nordkit\Wiretap\Laravel\Models\Trace;

trait HasTraces
{
    /**
     * Get all traces associated with the model.
     *
     * @return MorphMany<Trace, $this>
     */
    public function traces(): MorphMany
    {
        return $this->morphMany(config('wiretap.model', Trace::class), 'traceable');
    }
}
