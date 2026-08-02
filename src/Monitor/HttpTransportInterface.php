<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * The single point at which this application performs an outbound HTTP request.
 *
 * Exists so the redirect boundary can be proven **at the transport seam**: a
 * test double records every URL that reaches this interface, and the assertion
 * that a protected target was never requested becomes an assertion about a
 * concrete list rather than an inference from control flow.
 *
 * Deliberately NOT a logging hook. Production wires
 * {@see StreamHttpTransport} and records nothing — a monitor that kept a log
 * of every URL it touched would itself become a store of the locators the
 * specification says we do not retain (§3, "no portal URLs"). The recording
 * lives only in the test double.
 */
interface HttpTransportInterface
{
    /**
     * Perform exactly ONE request. Never follow redirects.
     *
     * Returning the redirect response rather than following it is what lets
     * {@see HttpPageFetcher} inspect each hop's target before deciding whether
     * to request it.
     *
     * @return array{int, array<string, string>, string, ?string}|null
     *         [status, lowercase-keyed headers, body, error] or null when the
     *         connection could not be opened at all.
     */
    public function send(string $url, int $timeoutSeconds, string $userAgent, int $maxBytes): ?array;
}
