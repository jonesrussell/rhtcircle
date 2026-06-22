<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analytics\AnalyticsRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public ingest endpoint for first-party analytics beacons.
 *
 * Always answers 204, even on rejection, so clients and bots learn nothing
 * about validation. Abuse surface is limited by a body-size cap, an origin
 * check, and the recorder's own field validation and bot filtering.
 */
final class CollectController
{
    private const MAX_BODY_BYTES = 2048;

    public function __construct(private readonly AnalyticsRecorder $recorder) {}

    public function collect(Request $request): Response
    {
        $raw = $request->getContent();

        if (
            $raw !== '' && strlen($raw) <= self::MAX_BODY_BYTES
            && $this->originAllowed($request)
        ) {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->recorder->record(
                    $data,
                    $request->getClientIp(),
                    $request->headers->get('User-Agent'),
                );
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Lenient origin check: reject only when an Origin/Referer is present and
     * its host does not match the request host. Absent headers are allowed so
     * legitimate same-origin beacons are never dropped.
     */
    private function originAllowed(Request $request): bool
    {
        $host = $request->getHost();

        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->headers->get($header);
            if ($value === null || $value === '') {
                continue;
            }
            $candidate = parse_url($value, PHP_URL_HOST);

            return is_string($candidate) && strcasecmp($candidate, $host) === 0;
        }

        return true;
    }
}
