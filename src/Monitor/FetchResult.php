<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * The outcome of one page fetch.
 *
 * A transport failure is represented as a **value**, not an exception: the
 * collector must be able to tell "the fetch failed" from "the page is gone",
 * because conflating them publishes a false removal the first time the site is
 * briefly unreachable (spec §4.3 step 7, and the two-run rule that follows from
 * it).
 *
 * @api
 */
final readonly class FetchResult
{
    /**
     * @param array<string, string> $headers Lowercase header names.
     */
    private function __construct(
        public bool $ok,
        public int $statusCode,
        public string $finalUrl,
        public string $body,
        public array $headers,
        public ?string $error,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function success(int $statusCode, string $finalUrl, string $body, array $headers = []): self
    {
        return new self(true, $statusCode, $finalUrl, $body, $headers, null);
    }

    /**
     * A transport-level failure: timeout, DNS, connection refused, oversize
     * response, a redirect off-origin. Carries no body, so nothing downstream
     * can accidentally hash or snapshot it.
     */
    public static function failure(string $url, string $error): self
    {
        return new self(false, 0, $url, '', [], $error);
    }
}
