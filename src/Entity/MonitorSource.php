<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldStorage;

/**
 * A monitored public website, and whether the monitoring itself is healthy.
 *
 * Public-website sources only (spec §3.1). There is no portal source and no
 * `kind` field: the members portal is properly access-controlled and is not
 * monitored, so there is nothing here that could be pointed at it.
 *
 * Registration constraints that are load-bearing for the exposure boundary
 * (spec §6.2) and asserted declaratively by NoAutoExposureTest:
 *   - no `api: true`     -> no JSON:API route is generated
 *   - no `group: 'content'` -> PublishedContentAccessPolicy never grants view,
 *                              which keeps MCP, SSR, GraphQL and Discovery shut
 *   - no `status` field  -> WorkflowVisibility::isEntityPublic() can never be
 *                           true, so the Discovery routes stay closed even if a
 *                           policy is added later
 */
#[ContentEntityType(id: 'monitor_source', label: 'Monitor source')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label')]
final class MonitorSource extends ContentEntityBase
{
    #[Field(label: 'Key', description: 'Stable slug, e.g. sagamok_public_site.', required: true, settings: ['weight' => 0], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $key = '';

    #[Field(label: 'Label', required: true, settings: ['weight' => 1], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $label = '';

    #[Field(label: 'Origin URL', description: 'A public website URL. Public because the site is public.', required: true, settings: ['weight' => 2], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $origin_url = '';

    #[Field(required: false, type: 'boolean', label: 'Enabled', description: 'Operator switch.', settings: ['weight' => 3], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public bool $enabled = true;

    #[Field(required: false, label: 'Health', description: 'ok | degraded | failing.', settings: ['weight' => 4], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public string $health = 'ok';

    #[Field(required: false, type: 'integer', label: 'Last check started', description: 'Unix seconds.', settings: ['weight' => 5], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $last_check_started = 0;

    /**
     * Stored alongside last_check_started so a run that never finished shows as
     * a stall rather than reporting stale data as fresh (spec §3.1).
     */
    #[Field(required: false, type: 'integer', label: 'Last check completed', description: 'Unix seconds.', settings: ['weight' => 6], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $last_check_completed = 0;

    #[Field(required: false, type: 'integer', label: 'Last success', description: 'Last check that completed without error, unix seconds.', settings: ['weight' => 7], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $last_success = 0;

    #[Field(required: false, type: 'integer', label: 'Consecutive failures', settings: ['weight' => 8], stored: FieldStorage::Column, read: FieldReadLevel::Public)]
    public int $consecutive_failures = 0;

    /**
     * Internal: diagnostic text may quote a response fragment. Readable only
     * through the audited CLI report (spec §6.4), never through a projection.
     */
    #[Field(required: false, type: 'text', label: 'Last error', settings: ['weight' => 9], stored: FieldStorage::Data, read: FieldReadLevel::Internal)]
    public string $last_error = '';
}
