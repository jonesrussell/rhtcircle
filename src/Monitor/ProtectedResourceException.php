<?php

declare(strict_types=1);

namespace App\Monitor;

/**
 * Raised when the monitor was about to touch a members-only resource.
 *
 * This is a **defense-in-depth** signal, not an expected control-flow path.
 * Discovery, redirect processing and gating are each supposed to have declined
 * long before anything reaches {@see CrawlBoundary::assertPublic()}. Reaching
 * this exception means one of those stages has a gap, so it fails loudly and
 * carries the stage that caught it — a silent skip here would restore exactly
 * the class of bug this machinery exists to prevent.
 *
 * The URL is kept for the operator-facing message only. Callers must not
 * persist it: a protected URL is itself something the monitor does not store
 * (spec §3, "no portal URLs"). {@see PublicSiteCollector} logs the *stage* and
 * a non-reversible digest, never the path.
 */
final class ProtectedResourceException extends \RuntimeException
{
    public function __construct(
        public readonly string $url,
        public readonly string $stage,
    ) {
        parent::__construct(sprintf(
            'Refused to %s a members-only resource. The crawl boundary should have declined this earlier; '
            . 'this is a defect in discovery, redirect handling or gating, not a routine skip.',
            $stage,
        ));
    }
}
