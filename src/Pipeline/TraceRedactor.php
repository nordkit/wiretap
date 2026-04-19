<?php

declare(strict_types=1);

namespace Nordkit\Wiretap\Pipeline;

use Nordkit\Wiretap\HttpExchange;

/**
 * Redacts sensitive data from an HttpExchange before it is persisted.
 *
     * Steps applied in order:
     *  1. Strip / replace matching request and response headers with "[REDACTED]".
     *  2. Null out bodies with binary content types (multipart/form-data, application/octet-stream).
     *  3. Recursively replace matching JSON or form-encoded body keys with "[REDACTED]".
     *  4. Truncate bodies that exceed max_body_bytes.
     *  5. Null out bodies if store_request_body / store_response_body is false.
 */
class TraceRedactor
{
    /**
     * @param array{
     *     redact_string: string,
     *     store_request_body: bool,
     *     store_response_body: bool,
     *     max_body_bytes: int|null,
     *     redact_request_headers: list<string>,
     *     redact_response_headers: list<string>,
     *     redact_body_keys: list<string>,
     * } $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Apply all redaction and truncation rules to a log entry, returning a new sanitised instance.
     */
    public function redact(HttpExchange $entry): HttpExchange
    {
        return new HttpExchange(
            direction      : $entry->direction,
            driver         : $entry->driver,
            url            : $entry->url,
            method         : $entry->method,
            requestHeaders : $this->redactHeaders($entry->requestHeaders, $this->config['redact_request_headers']),
            requestBody    : $this->processBody($entry->requestBody, $this->config['store_request_body'], $entry->requestHeaders),
            responseStatus : $entry->responseStatus,
            responseHeaders: $this->redactHeaders($entry->responseHeaders, $this->config['redact_response_headers']),
            responseBody   : $this->processBody($entry->responseBody, $this->config['store_response_body'], $entry->responseHeaders),
            durationMs     : $entry->durationMs,
            errorMessage   : $entry->errorMessage,
            traceable      : $entry->traceable,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $redactKeys
     * @return array<string, string>
     */
    private function redactHeaders(array $headers, array $redactKeys): array
    {
        $normalized = array_map('strtolower', $redactKeys);
        $result = [];
        $redactString = $this->config['redact_string'];

        foreach ($headers as $key => $value) {
            $result[$key] = in_array(strtolower($key), $normalized, strict: true)
                ? $redactString
                : $value;
        }

        return $result;
    }

    /**
     * Conditionally redact body keys, truncate to max_body_bytes, and null out if capture is disabled.
     *
     * @param  array<string, string>  $headers
     */
    private function processBody(?string $body, bool $shouldTrace, array $headers): ?string
    {
        if (! $shouldTrace || $body === null) {
            return null;
        }

        // Null out binary content types — cannot be meaningfully redacted or stored as text.
        $contentType = '';
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'content-type') {
                $contentType = is_array($v) ? $v[0] : $v;
                break;
            }
        }

        if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/octet-stream')) {
            return null;
        }

        // Truncate the raw body BEFORE redacting so redaction always operates on complete, valid data.
        if ($this->config['max_body_bytes'] !== null && strlen($body) > $this->config['max_body_bytes']) {
            return substr($body, 0, $this->config['max_body_bytes']).'... [TRUNCATED]';
        }

        return $this->redactBodyKeys($body, $headers);
    }

    /**
     * Inspect the Content-Type header and redact matching keys from a JSON or
     * form-encoded body. Returns the body unchanged if neither format is detected.
     *
     * @param  array<string, string>  $headers
     */
    private function redactBodyKeys(string $body, array $headers): string
    {
        if (empty($this->config['redact_body_keys'])) {
            return $body;
        }

        $contentType = '';
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'content-type') {
                $contentType = is_array($v) ? $v[0] : $v;
                break;
            }
        }

        // Always check JSON first or if context type matches
        if (str_contains($contentType, 'application/json') || $contentType === '') {
            $decoded = json_decode($body, associative: true);
            if (is_array($decoded)) {
                $redacted = $this->recursiveRedact($decoded, $this->config['redact_body_keys']);

                try {
                    return json_encode($redacted, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
                } catch (\JsonException) {
                    return $body;
                }
            }
        }

        // application/x-www-form-urlencoded body
        if (str_contains($contentType, 'application/x-www-form-urlencoded') || ($contentType === '' && str_contains($body, '='))) {
            parse_str($body, $formData);
            if (! empty($formData)) {
                $redacted = $this->recursiveRedact($formData, $this->config['redact_body_keys']);

                return http_build_query($redacted);
            }
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private function recursiveRedact(array $data, array $keys): array
    {
        $normalized = array_map('strtolower', $keys);
        $redactString = $this->config['redact_string'];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $normalized, strict: true)) {
                $data[$key] = $redactString;
            } elseif (is_array($value)) {
                $data[$key] = $this->recursiveRedact($value, $keys);
            }
        }

        return $data;
    }
}
