<?php

declare(strict_types=1);

namespace App\Schedule;

use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduledTask;

/**
 * The Sagamok public-website monitor's scheduled entry point (spec §4.1).
 *
 * **Declared but disabled.** This class is listed in `schedule.disabled_entries`
 * in `config/waaseyaa.php`, so the kernel discovers it, `schedule:list` can show
 * it, and enabling it is a one-line reviewable configuration change — but it
 * does not run.
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
