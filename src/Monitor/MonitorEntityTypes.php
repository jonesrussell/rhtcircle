<?php

declare(strict_types=1);

namespace App\Monitor;

use App\Entity\MonitorEvent;
use App\Entity\MonitorIssue;
use App\Entity\MonitorItem;
use App\Entity\MonitorOfficialUpdate;
use App\Entity\MonitorSource;
use App\Entity\PortalAccessState;
use App\Entity\PortalUpdateNote;

/**
 * The canonical inventory of monitor entity types.
 *
 * One list, consumed by the service provider (registration), the access policy
 * (`appliesTo`), and the exposure tests (which iterate it so a newly added type
 * cannot silently escape the lockdown). Adding a type here without satisfying
 * the §6.2 rules will fail NoAutoExposureTest, which is the point.
 *
 * @api
 */
final class MonitorEntityTypes
{
    public const string SOURCE = 'monitor_source';
    public const string ITEM = 'monitor_item';
    public const string EVENT = 'monitor_event';
    public const string ISSUE = 'monitor_issue';
    public const string OFFICIAL_UPDATE = 'monitor_official_update';
    public const string PORTAL_ACCESS_STATE = 'portal_access_state';
    public const string PORTAL_UPDATE_NOTE = 'portal_update_note';

    /** @var list<string> */
    public const array ALL = [
        self::SOURCE,
        self::ITEM,
        self::EVENT,
        self::ISSUE,
        self::OFFICIAL_UPDATE,
        self::PORTAL_ACCESS_STATE,
        self::PORTAL_UPDATE_NOTE,
    ];

    /** @var array<string, class-string> */
    public const array CLASSES = [
        self::SOURCE => MonitorSource::class,
        self::ITEM => MonitorItem::class,
        self::EVENT => MonitorEvent::class,
        self::ISSUE => MonitorIssue::class,
        self::OFFICIAL_UPDATE => MonitorOfficialUpdate::class,
        self::PORTAL_ACCESS_STATE => PortalAccessState::class,
        self::PORTAL_UPDATE_NOTE => PortalUpdateNote::class,
    ];
}
