<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\MonitorDashboardAccessPolicy;
use App\Monitor\ExclusionKind;
use App\Monitor\MonitorEntityTypes;
use App\Monitor\SagamokMonitorRepository;
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

    public function dashboard(int $now): Response
    {
        return $this->renderer->html('pages/communities/sagamok/monitor.html.twig', [
            'sources' => $this->sources($now),
            'changes' => $this->recentChanges(),
            'issues' => $this->openIssues(),
            'updates' => $this->officialUpdates(),
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

    /** @return list<array<string, scalar>> */
    private function sources(int $now): array
    {
        return $this->projection->viewAll(
            $this->entityTypes->getRepository(MonitorEntityTypes::SOURCE)->findBy([]),
            $now,
        );
    }

    /**
     * Recent change events, newest first, redacted rows excluded.
     *
     * @return list<array<string, scalar>>
     */
    private function recentChanges(): array
    {
        $events = [];
        foreach ($this->entityTypes->getRepository(MonitorEntityTypes::EVENT)->findBy([]) as $event) {
            if ((int) $event->get('redacted_at') !== 0) {
                continue;
            }
            $events[] = $this->projection->view($event);
        }

        usort($events, static fn (array $a, array $b): int => ((int) $b['observed_at']) <=> ((int) $a['observed_at']));

        return array_slice($events, 0, 50);
    }

    /** @return list<array<string, scalar>> */
    private function openIssues(): array
    {
        $issues = [];
        foreach ($this->entityTypes->getRepository(MonitorEntityTypes::ISSUE)->findBy([]) as $issue) {
            $view = $this->projection->view($issue);
            if (in_array((string) $view['issue_state'], ['open', 'awaiting_response', 'partly_answered'], true)) {
                $issues[] = $view;
            }
        }

        // By date opened. Never by severity: that is an Internal editorial
        // judgement, and a public list ordered by it publishes the ranking
        // without ever rendering the field.
        usort($issues, static fn (array $a, array $b): int => ((int) $b['opened_at']) <=> ((int) $a['opened_at']));

        return $issues;
    }

    /** @return list<array<string, scalar>> */
    private function officialUpdates(): array
    {
        $updates = $this->projection->viewAll(
            $this->entityTypes->getRepository(MonitorEntityTypes::OFFICIAL_UPDATE)->findBy([]),
        );

        usort($updates, static fn (array $a, array $b): int => ((int) $b['published_at']) <=> ((int) $a['published_at']));

        return $updates;
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
