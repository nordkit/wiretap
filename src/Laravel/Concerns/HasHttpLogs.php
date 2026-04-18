<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nordkit\Wiretap\Laravel\Models\HttpLog;

trait HasHttpLogs
{
    /**
     * Get all the HTTP logs associated with the model.
     *
     * @return MorphMany<HttpLog, $this>
     */
    public function httpLogs(): MorphMany
    {
        return $this->morphMany(config('wiretap.model', HttpLog::class), 'loggable');
    }
}
