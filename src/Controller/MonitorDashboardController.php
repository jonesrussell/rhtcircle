<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\MonitorDashboardAccessPolicy;
use App\Monitor\ExclusionKind;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
use App\Provider\SagamokMonitorServiceProvider;
use App\Rendering\SiteRenderer;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * The public monitoring dashboard (spec §8).
 *
 * Everything rendered here passes through
 * {@see SagamokMonitorRepository::view()}, the closed public projection. The
 * controller never touches an entity field directly, so a field added to a
 * monitor entity tomorrow cannot reach a template by accident — it has to be
 * added to the projection first, which is a decision someone makes on purpose.
 *
 * Templates receive **flat arrays only** and contain no data literals.
 */
final class MonitorDashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly SiteRenderer $renderer,
        private readonly SagamokMonitorRepository $projection,
    ) {}

    public function dashboard(int $now, int $page = 1): Response
    {
        $sources = $this->listing(SagamokMonitorServiceProvider::LISTING_SOURCES, 1, $now);
        $changes = $this->listing(SagamokMonitorServiceProvider::LISTING_TIMELINE, $page, $now);
        $issues = $this->listing(SagamokMonitorServiceProvider::LISTING_ISSUES_OPEN, 1, $now);
        $updates = $this->listing(SagamokMonitorServiceProvider::LISTING_UPDATES, 1, $now);

        return $this->renderer->html('pages/communities/sagamok/monitor.html.twig', [
            'sources' => $sources['rows'],
            'changes' => $changes['rows'],
            'changes_pagination' => $changes['pagination'],
            'issues' => $issues['rows'],
            'updates' => $updates['rows'],
            'portal' => $this->portalStatus(),
            'exclusion_labels' => [
                ExclusionKind::AuthRequired->value => ExclusionKind::AuthRequired->publicLabel(),
                ExclusionKind::NotForRetention->value => ExclusionKind::NotForRetention->publicLabel(),
            ],
            // Named so a reviewer can see at a glance which ability opens this
            // page. Nothing else in the app requests it.
            'required_ability' => MonitorDashboardAccessPolicy::ABILITY,
        ]);
    }

    public function issue(string $slug): Response
    {
        $issue = null;
        foreach ($this->entityTypes->getRepository(MonitorEntityTypes::ISSUE)->findBy(['slug' => $slug]) as $candidate) {
            $issue = $this->projection->view($candidate);
            break;
        }

        if ($issue === null) {
            return $this->renderer->html('404.html.twig', ['path' => '/communities/sagamok/monitor/' . $slug], 404);
        }

        $updates = [];
        foreach ($this->entityTypes->getRepository(MonitorEntityTypes::OFFICIAL_UPDATE)->findBy(['issue_slug' => $slug]) as $update) {
            $updates[] = $this->projection->view($update);
        }

        return $this->renderer->html('pages/communities/sagamok/monitor-issue.html.twig', [
            'issue' => $issue,
            'updates' => $updates,
        ]);
    }

    /**
     * Every dashboard section resolves a registered `ListingDefinition`.
     *
     * No full-table scan, no manual array slicing, no `usort()` in the
     * controller: the sort, page size, access ops and cache tags all live in
     * the definition, and `ListingResolver` is what applies them — including
     * the bespoke `monitor.dashboard_read` gate. Reading repositories directly
     * would leave those definitions as decoration.
     *
     * @return array{rows: list<array<string, scalar>>, pagination: array<string, mixed>}
     */
    private function listing(string $listingId, int $page = 1, int $now = 0): array
    {
        return $this->projection->resolveListing($listingId, $page, $now);
    }

    /** @return array<string, scalar>|null */
    private function portalStatus(): ?array
    {
        foreach ($this->entityTypes->getRepository(MonitorEntityTypes::PORTAL_ACCESS_STATE)->findBy([]) as $state) {
            return $this->projection->view($state);
        }

        return null;
    }
}
