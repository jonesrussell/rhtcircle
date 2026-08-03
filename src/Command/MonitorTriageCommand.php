<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitor\MonitorEntityTypes;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Security\CliFieldReadCapabilityDeclaration;
use Waaseyaa\CLI\Security\CliFieldReadCapabilityIssuer;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityReason;

/**
 * `bin/waaseyaa sagamok:monitor-triage` — the **sanctioned reader** for the
 * monitor's Internal values (spec §6.4).
 *
 * `severity`, `answers_ask`, `last_error`, `notes`, `method_note` and
 * `review_note` are `FieldReadLevel::Internal`, so a plain getter throws. They
 * are reachable only here, through `AuditedFieldRead` under a capability minted
 * by `CliFieldReadCapabilityIssuer`: CLI-only, TTL-bounded, reason-scoped and
 * ledgered, with one strict ledger entry reserved per read.
 *
 * Two shortcuts are deliberately not taken, and neither should be introduced
 * later to make this simpler:
 *
 *  - **No permissive `ProtectedReadPolicyProviderInterface`.**
 *  - **No `EntityReadRuntime::installGuard(null)`.**
 *
 * Either would re-open every Internal field in the entire application, not just
 * the six this report needs — turning a narrow maintainer tool into a
 * process-wide hole.
 *
 * Fails closed: without a live capability the read raises `FieldReadDenied` and
 * the command reports it rather than falling back to a partial report, because
 * a triage report that silently omits the values it exists to show is worse
 * than an error.
 */
final class MonitorTriageCommand
{
    /** The Internal fields this report is allowed to read, per entity type. */
    private const READS = [
        MonitorEntityTypes::ISSUE => ['severity'],
        MonitorEntityTypes::OFFICIAL_UPDATE => ['answers_ask'],
        MonitorEntityTypes::SOURCE => ['last_error'],
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypes,
        private readonly CliFieldReadCapabilityIssuer $issuer,
        private readonly AuditedFieldRead $reader,
    ) {}

    public function run(SymfonyCommandIO $io, string $justification, \DateTimeImmutable $expiresAt): int
    {
        if (trim($justification) === '') {
            $io->error('A justification is required: this report reads Internal editorial values and every read is ledgered.');

            return 1;
        }

        $boundary = new CapabilityExecutionBoundary(bin2hex(random_bytes(8)));
        $rows = 0;

        foreach (self::READS as $entityTypeId => $fields) {
            $declaration = new CliFieldReadCapabilityDeclaration(
                command: 'sagamok:monitor-triage',
                reason: CapabilityReason::MaintenanceCli,
                entityTypes: [$entityTypeId],
                bundles: [],
                fields: $fields,
                justification: $justification,
            );

            try {
                $this->issuer->register($declaration);
                $capability = $this->issuer->issue($declaration, $boundary, $expiresAt);
            } catch (\Throwable $e) {
                $io->error(sprintf('Could not obtain a field-read capability for %s: %s', $entityTypeId, $e->getMessage()));

                return 1;
            }

            $io->writeln('');
            $io->writeln(sprintf('== %s ==', $entityTypeId));

            foreach ($this->entityTypes->getRepository($entityTypeId)->findBy([]) as $entity) {
                try {
                    $values = $this->reader->readMany(
                        $capability,
                        $boundary,
                        $entity,
                        $fields,
                        CapabilityReason::MaintenanceCli,
                    );
                } catch (\Throwable $e) {
                    // Fail closed and stop: a partial triage report invites a
                    // decision made on a value that was silently missing.
                    $io->error(sprintf('Audited read denied for %s: %s', $entityTypeId, $e->getMessage()));

                    return 1;
                }

                $parts = [];
                foreach ($values as $field => $value) {
                    $parts[] = sprintf('%s=%s', $field, is_scalar($value) ? (string) $value : gettype($value));
                }
                $io->writeln(sprintf('  #%s  %s', (string) $entity->id(), implode('  ', $parts)));
                ++$rows;
            }
        }

        $io->writeln('');
        $io->writeln(sprintf('%d row(s) read under boundary %s. Every read is in the strict ledger.', $rows, $boundary->correlationId));

        return 0;
    }
}
