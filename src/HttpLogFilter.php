<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

/**
 * Determines whether an HttpLogEntry should be persisted based on config rules.
 *
 * Precedence (first match wins):
 *  1. Global enabled flag is false -> discard.
 *  2. Host in exclude_hosts -> discard.
 *  3. include_hosts non-empty and host NOT in list -> discard.
 *  4. URL matches an exclude_paths regex -> discard.
 *  5. Otherwise -> log.
 */
class HttpLogFilter
{
    /** @param array{enabled: bool, include_hosts: list<string>, exclude_hosts: list<string>, exclude_paths: list<string>} $config */
    public function __construct(private readonly array $config)
    {
        foreach ($this->config['exclude_paths'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid exclude_paths regex: {$pattern}");
            }
        }
    }

    /**
     * Returns true if the entry passes all filter rules and should be persisted.
     */
    public function shouldLog(HttpLogEntry $entry): bool
    {
        if (! $this->config['enabled']) {
            return false;
        }

        $parsedHost = @parse_url($entry->url, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';

        if ($this->hostIsExcluded($host)) {
            return false;
        }
        if ($this->hostIsNotIncluded($host)) {
            return false;
        }
        if ($this->pathIsExcluded($entry->url)) {
            return false;
        }

        return true;
    }

    private function hostIsExcluded(string $host): bool
    {
        foreach ($this->config['exclude_hosts'] as $excluded) {
            if (fnmatch($excluded, $host)) {
                return true;
            }
        }

        return false;
    }

    private function hostIsNotIncluded(string $host): bool
    {
        if (empty($this->config['include_hosts'])) {
            return false;
        }

        foreach ($this->config['include_hosts'] as $included) {
            if (fnmatch($included, $host)) {
                return false;
            }
        }

        return true;
    }

    private function pathIsExcluded(string $url): bool
    {
        foreach ($this->config['exclude_paths'] as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
