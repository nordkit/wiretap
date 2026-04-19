<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Laravel\Commands;

use Illuminate\Console\Command;
use Nordkit\Wiretap\Laravel\Models\Trace;

/**
 * Deletes traces older than the configured retention window.
 *
 * Registered and scheduled automatically by WiretapServiceProvider when
 * wiretap.pruning.enabled is true and wiretap.driver is 'database'.
 *
 * Run manually:
 *   php artisan wiretap:prune
 *   php artisan wiretap:prune --days=30
 */
class PruneTracesCommand extends Command
{
    /** @var string */
    protected $signature = 'wiretap:prune
                            {--days= : Override the number of days to keep (default: wiretap.pruning.keep_days)}';

    /** @var string */
    protected $description = 'Delete Wiretap traces older than the configured retention window';

    public function handle(): int
    {
        if (config('wiretap.driver') !== 'database') {
            $this->components->warn('wiretap:prune has no effect when wiretap.driver is not "database".');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('wiretap.pruning.keep_days', 90));

        $deleted = Trace::where('created_at', '<=', now()->subDays($days))->delete();

        $this->components->info("Deleted {$deleted} Wiretap trace(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
