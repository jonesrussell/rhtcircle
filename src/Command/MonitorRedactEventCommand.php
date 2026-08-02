<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitorEvent;
use App\Monitor\CollectorState;
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

    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly CollectorState $state,
    ) {}

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

        // Purge the retained content FIRST, and only report success if it
        // worked (review finding 2).
        //
        // This used to clear `evidence_url` and `notes` and call that "the part
        // that resolves to content". It is not. The snapshot table holds the
        // page body, and the item holds a title extracted from it. A redaction
        // issued because a page carried personal information was leaving the
        // copy of that page in place, and telling the operator it was done.
        //
        // Ordering is deliberate: if the purge fails we must not have already
        // written a stub that claims the content is gone. A redaction reported
        // as complete while the body survives is worse than a failed redaction,
        // because nobody looks again.
        $purge = $this->purgeContentFor($event);
        if ($purge === null) {
            $io->error(sprintf(
                'Could not purge retained content for event "%s". The redaction was NOT applied; '
                . 'the event is unchanged and the content is still stored. Re-run once the cause is fixed.',
                $eventId,
            ));

            return 1;
        }

        // The stub: the row, its type, its timestamps and its item reference
        // all remain, so the log cannot develop silent holes.
        $event->set('redacted_at', $now);
        $event->set('redaction_reason', $reason);
        $event->set('evidence_url', '');
        $event->set('notes', '');

        $repository->save($event, validate: false);

        $io->writeln(sprintf(
            'Redacted event %s (%s). Purged %d snapshot(s) and cleared the content-derived title. '
            . 'The log entry is retained as a stub.',
            $eventId,
            $reason,
            $purge['snapshots'],
        ));

        return 0;
    }

    /**
     * Remove every retained artefact behind one event.
     *
     * Returns null when the purge could not be completed, which the caller
     * treats as a failed redaction. Returns a count otherwise — including zero,
     * which is legitimate for an event whose item never carried a body.
     *
     * @return array{snapshots: int}|null
     */
    private function purgeContentFor(MonitorEvent $event): ?array
    {
        $sourceKey = (string) $event->get('source_key');
        $publicRef = (string) $event->get('item_public_ref');

        if ($sourceKey === '' || $publicRef === '') {
            // Nothing addressable to purge. Not a failure: some events are not
            // item-scoped, and those carry no retained body by construction.
            return ['snapshots' => 0];
        }

        try {
            $snapshots = 0;
            $itemKey = $this->state->itemKeyForPublicRef($sourceKey, $publicRef);
            if ($itemKey !== null) {
                $snapshots = $this->state->purgeRetainedContent($sourceKey, $itemKey);
            }

            $this->clearContentDerivedItemFields($sourceKey, $publicRef);

            return ['snapshots' => $snapshots];
        } catch (\Throwable) {
            // Deliberately swallowed into a null return rather than rethrown:
            // the caller turns this into a non-zero exit and an explicit "not
            // applied" message, which is what an operator needs to see.
            return null;
        }
    }

    /**
     * Drop the title and locator learned from the redacted body.
     *
     * A redaction that removed the snapshot but left "Council minutes — member
     * addresses" on the dashboard would still be publishing the thing it was
     * asked to remove.
     */
    private function clearContentDerivedItemFields(string $sourceKey, string $publicRef): void
    {
        $items = $this->entityTypes->getRepository(MonitorEntityTypes::ITEM);
        foreach ($items->findBy(['source_key' => $sourceKey, 'public_ref' => $publicRef]) as $item) {
            $item->set('title', 'Redacted at request');
            $item->set('public_url', '');
            $items->save($item, validate: false);
        }
    }
}
