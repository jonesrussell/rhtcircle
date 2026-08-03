<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitorEvent;
use App\Monitor\CollectorState;
use App\Monitor\MonitorEntityTypes;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
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
        private readonly DatabaseInterface $database,
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

        // This used to clear `evidence_url` and `notes` and call that "the part
        // that resolves to content". It is not. The snapshot table holds the
        // page body, and the item holds a title extracted from it. A redaction
        // issued because a page carried personal information was leaving the
        // copy of that page in place, and telling the operator it was done
        // (review finding 2).
        //
        // ALL of it, or none of it. A partial redaction is the worst outcome
        // available: the operator is told the content is gone, nobody looks
        // again, and some of it is still there. Wrapping the whole sequence in
        // one transaction makes that state unreachable — an exception anywhere
        // between the first purge and the stub write rolls back every earlier
        // step, including the snapshot deletes.
        $transaction = $this->database->transaction('monitor_redact_event');

        try {
            $purged = $this->purgeContentFor($event, $reason, $now);

            // The stub: the row, its type, its timestamps and its item
            // reference all remain, so the log cannot develop silent holes.
            $event->set('redacted_at', $now);
            $event->set('redaction_reason', $reason);
            $event->set('evidence_url', '');
            $event->set('notes', '');
            $repository->save($event, validate: false);

            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollBack();

            $io->error(sprintf(
                'Could not complete redaction of event "%s": %s. NOTHING was changed — the event is '
                . 'unredacted and the retained content is still stored. Re-run once the cause is fixed.',
                $eventId,
                $error->getMessage(),
            ));

            return 1;
        }

        $io->writeln(sprintf(
            'Redacted event %s (%s). Purged %d snapshot(s) and cleared the content-derived title. '
            . 'The log entry is retained as a stub.',
            $eventId,
            $reason,
            $purged,
        ));

        return 0;
    }

    /**
     * Remove every retained artefact behind one event.
     *
     * Throws rather than returning a failure value: the caller runs this inside
     * a transaction, and an exception is what rolls the whole redaction back.
     * Catching here would let a half-finished purge commit.
     *
     * Returns the number of snapshots removed — zero is legitimate for an event
     * that is not item-scoped and therefore carries no retained body.
     */
    private function purgeContentFor(MonitorEvent $event, string $reason, int $now): int
    {
        $sourceKey = (string) $event->get('source_key');
        $publicRef = (string) $event->get('item_public_ref');

        if ($sourceKey === '' || $publicRef === '') {
            return 0;
        }

        $snapshots = 0;
        $itemKey = $this->state->itemKeyForPublicRef($sourceKey, $publicRef);
        if ($itemKey !== null) {
            $snapshots = $this->state->purgeRetainedContent($sourceKey, $itemKey);
            // The tombstone. Purging alone made redaction durable only until
            // the next crawl: the state row kept its content hash, so the
            // following content change re-hashed the body, appended a fresh
            // snapshot and restored the public locator — quietly undoing a
            // removal someone had asked for, with nobody looking again because
            // the command had already reported success.
            //
            // Set inside the caller's transaction, so a failed redaction leaves
            // no suppression behind either.
            $this->state->suppressRetention($sourceKey, $itemKey, $reason, $now);
        }

        $this->clearContentDerivedItemFields($sourceKey, $publicRef);

        return $snapshots;
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
