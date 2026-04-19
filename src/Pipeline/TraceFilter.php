<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Pipeline;

use Nordkit\Wiretap\HttpDirection;
use Nordkit\Wiretap\HttpExchange;

/**
 * Determines whether an HttpExchange should be persisted based on config rules.
 *
 * Precedence (first match wins):
 *  1. Global enabled flag is false -> discard.
 *  2. Host in the direction's exclude_hosts -> discard.
 *  3. direction's include_hosts non-empty and host NOT in list -> discard.
 *  4. URL matches the direction-specific exclude_paths regex -> discard.
 *  5. direction's include_paths non-empty and URL does NOT match any -> discard.
 *  6. Otherwise -> trace.
 *
 * Outbound uses outbound.include_hosts / outbound.exclude_hosts / outbound.include_paths / outbound.exclude_paths.
 * Inbound  uses inbound.include_hosts / inbound.exclude_hosts / inbound.include_paths / inbound.exclude_paths.
 */
class TraceFilter
{
    /**
     * @param array{
     *     enabled: bool,
     *     include_hosts: list<string>,
     *     exclude_hosts: list<string>,
     *     include_paths: list<string>,
     *     exclude_paths: list<string>,
     *     inbound_include_hosts: list<string>,
     *     inbound_exclude_hosts: list<string>,
     *     inbound_include_paths: list<string>,
     *     inbound_exclude_paths: list<string>,
     * } $config
     */
    public function __construct(private readonly array $config)
    {
        foreach ($this->config['include_paths'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid include_paths regex: {$pattern}");
            }
        }

        foreach ($this->config['exclude_paths'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid exclude_paths regex: {$pattern}");
            }
        }

        foreach ($this->config['inbound_include_paths'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid inbound_include_paths regex: {$pattern}");
            }
        }

        foreach ($this->config['inbound_exclude_paths'] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("Invalid inbound_exclude_paths regex: {$pattern}");
            }
        }
    }

    /**
     * Returns true if the entry passes all filter rules and should be persisted.
     */
    public function shouldTrace(HttpExchange $entry): bool
    {
        if (! $this->config['enabled']) {
            return false;
        }

        $parsedHost = @parse_url($entry->url, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';

        $isInbound = $entry->direction === HttpDirection::Inbound;

        $includeHosts = $isInbound ? $this->config['inbound_include_hosts'] : $this->config['include_hosts'];
        $excludeHosts = $isInbound ? $this->config['inbound_exclude_hosts'] : $this->config['exclude_hosts'];
        $includePaths = $isInbound ? $this->config['inbound_include_paths'] : $this->config['include_paths'];
        $excludePaths = $isInbound ? $this->config['inbound_exclude_paths'] : $this->config['exclude_paths'];

        if ($this->hostIsExcluded($host, $excludeHosts)) {
            return false;
        }
        if ($this->hostIsNotIncluded($host, $includeHosts)) {
            return false;
        }
        if ($this->pathIsExcluded($entry->url, $excludePaths)) {
            return false;
        }
        if ($this->pathIsNotIncluded($entry->url, $includePaths)) {
            return false;
        }

        return true;
    }

    /** @param list<string> $excludeHosts */
    private function hostIsExcluded(string $host, array $excludeHosts): bool
    {
        foreach ($excludeHosts as $excluded) {
            if (fnmatch($excluded, $host)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $includeHosts */
    private function hostIsNotIncluded(string $host, array $includeHosts): bool
    {
        if (empty($includeHosts)) {
            return false;
        }

        foreach ($includeHosts as $included) {
            if (fnmatch($included, $host)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $patterns */
    private function pathIsExcluded(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function pathIsNotIncluded(string $url, array $patterns): bool
    {
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return false;
            }
        }

        return true;
    }
}
