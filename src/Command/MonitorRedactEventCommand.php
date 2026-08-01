<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitorEvent;
use App\Monitor\MonitorEntityTypes;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * `bin/waaseyaa sagamok:monitor-redact-event <public_ref> <event_id> <reason>`
 *
 * The **only** write that ever touches an existing `monitor_event` (spec §3.7).
 *
 * Redaction suppresses a row from every projection while **retaining a stub**,
 * so the log cannot silently develop holes. That matters more than it might
 * seem: a change log that can lose entries without trace is not evidence of
 * anything, and this dashboard exists to be evidence. A reader can always see
 * that something was removed and when, just not what it said.
 *
 * Fails closed on an unknown id, an already-redacted row, or a reason outside
 * the fixed set — a free-text reason would itself become an unreviewed
 * publication surface.
 */
final class MonitorRedactEventCommand
{
    /**
     * Fixed reason labels. Public (they are rendered beside the stub), and
     * deliberately not free text.
     *
     * @var list<string>
     */
    public const array REASONS = [
        'member_request',
        'published_in_error',
        'personal_information',
        'inaccurate',
    ];

    public function __construct(private readonly EntityTypeManager $entityTypes) {}

    public function run(SymfonyCommandIO $io, string $eventId, string $reason, int $now): int
    {
        if (!in_array($reason, self::REASONS, true)) {
            $io->error(sprintf(
                'Unknown redaction reason "%s". Allowed: %s.',
                $reason,
                implode(', ', self::REASONS),
            ));

            return 1;
        }

        $repository = $this->entityTypes->getRepository(MonitorEntityTypes::EVENT);
        $event = $repository->find($eventId);

        if (!$event instanceof MonitorEvent) {
            // Fail closed and say nothing about what ids do exist.
            $io->error(sprintf('No monitor event with id "%s".', $eventId));

            return 1;
        }

        if ((int) $event->get('redacted_at') !== 0) {
            $io->error(sprintf('Event "%s" is already redacted; redaction is not repeatable.', $eventId));

            return 1;
        }

        // The stub: the row, its type, its timestamps and its item reference all
        // remain. Only the evidence locator is cleared, because that is the part
        // that resolves to content.
        $event->set('redacted_at', $now);
        $event->set('redaction_reason', $reason);
        $event->set('evidence_url', '');
        $event->set('notes', '');

        $repository->save($event, validate: false);

        $io->writeln(sprintf('Redacted event %s (%s). The log entry is retained as a stub.', $eventId, $reason));

        return 0;
    }
}
