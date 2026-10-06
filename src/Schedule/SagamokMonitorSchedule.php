<?php

declare(strict_types=1);

namespace App\Schedule;

use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduledTask;

/**
 * The Sagamok public-website monitor's scheduled entry point (spec §4.1).
 *
 * **Declared but disabled.** The task definition ships behind the explicit
 * `ENABLED` constant below. The kernel may discover this class, but register()
 * returns no task while that constant is false.
 *
 * That default is deliberate. Enabling it means this app begins making automated
 * requests to another Nation's website on a timer. That is a decision for the
 * maintainer and the Council, not a side effect of a deployment, and it should
 * require someone to type something.
 *
 * The task collects **public website content only**. There is no portal task
 * here and none can be added: the collector reaches the network solely through
 * `PageFetcherInterface`, which has no credential, cookie or archive surface.
 *
 * @api
 */
final class SagamokMonitorSchedule implements ScheduleEntriesInterface
{
    /**
     * Activation is a separate reviewed release decision.
     *
     * Keep this false while the dashboard is being deployed and verified.
     */
    public const bool ENABLED = false;

    /** The one task this app schedules. */
    public const string TASK_ID = 'sagamok-monitor-public';

    /** The CLI command it runs. */
    public const string COMMAND = 'sagamok:monitor-public';

    /**
     * Hourly, on the hour, once enabled.
     *
     * Paired with the approved 1-request-per-second crawl rate and the 300-URL
     * per-run ceiling, so a run is bounded well inside the interval.
     */
    public const string EXPRESSION = '0 * * * *';

    /**
     * @return array<string, ScheduledTask>
     */
    public function register(ScheduleInterface $schedule): array
    {
        if (!self::ENABLED) {
            return [];
        }

        $task = new ScheduledTask(
            name: self::TASK_ID,
            expression: self::EXPRESSION,
            command: self::COMMAND,
            preventOverlap: true,
            description: 'Observe the Sagamok public website for changes. Public pages only.',
        );

        $schedule->add($task);

        return [self::TASK_ID => $task];
    }
}
