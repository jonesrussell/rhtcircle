<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitor;

use App\Monitor\HttpTransportInterface;

/**
 * A transport that records every URL it is asked to fetch and serves canned
 * responses.
 *
 * This is the evidence surface for the redirect boundary. "The protected
 * target was never requested" becomes an assertion about `requestLog()` — a
 * concrete list of what actually reached the transport — rather than an
 * inference from reading control flow.
 *
 * The recording lives here, in the test double, and nowhere in production: a
 * monitor that kept a log of every URL it touched would itself become a store
 * of the locators the specification says it does not retain.
 */
final class RecordingHttpTransport implements HttpTransportInterface
{
    /** @var list<string> */
    private array $requested = [];

    /** @var array<string, array{int, array<string, string>, string, ?string}> */
    private array $responses = [];

    /** @param array<string, array{int, array<string, string>, string, ?string}> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /** A 30x pointing at $location. */
    public function redirect(string $from, string $location, int $status = 302): self
    {
        $this->responses[$from] = [$status, ['location' => $location], '', null];

        return $this;
    }

    /** A terminal 200 carrying $body. */
    public function page(string $url, string $body): self
    {
        $this->responses[$url] = [200, ['content-type' => 'text/html'], $body, null];

        return $this;
    }

    public function send(string $url, int $timeoutSeconds, string $userAgent, int $maxBytes): ?array
    {
        // Recorded BEFORE the canned lookup, so a URL that has no configured
        // response still counts as requested. Anything else would let an
        // unexpected request slip past the assertions unnoticed.
        $this->requested[] = $url;

        return $this->responses[$url] ?? [404, [], '', null];
    }

    /** @return list<string> every URL that reached the transport, in order */
    public function requestLog(): array
    {
        return $this->requested;
    }

    public function wasRequested(string $url): bool
    {
        return in_array($url, $this->requested, true);
    }

    /** Whether any requested URL's path sits under $pathPrefix. */
    public function anyRequestedPathContains(string $needle): bool
    {
        foreach ($this->requested as $url) {
            if (str_contains(strtolower(rawurldecode($url)), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
