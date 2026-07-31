<?php

declare(strict_types=1);

namespace App\Access;

use App\Monitor\MonitorEntityTypes;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * The single access policy for every monitor entity type, and the mechanism
 * that keeps them off every generic framework surface (spec §6.2).
 *
 * ## Why a bespoke ability rather than `view`
 *
 * Four framework surfaces are generic over ANY registered entity type and need
 * no opt-in: MCP `entity.read`/`entity.search`, GraphQL, `/api/discovery/*`,
 * and the SSR `/{type}/{id}` catch-all. For all four the only gate is the
 * entity access policy, and `EntityAccessHandler` starts Neutral with
 * entity-level access using `isAllowed()`, so Neutral is a denial. Granting no
 * `view` therefore closes all four at once.
 *
 * But the Listing pipeline applies a per-row gate and `ListingDefinition`
 * rejects an empty `accessOps`, so a listing cannot opt out of the check: a
 * type that denies `view` to everyone would return no listing rows either, and
 * the dashboard would render nothing.
 *
 * The resolution uses the fact that `GateInterface::allows(string $ability, …)`
 * takes a FREE-FORM ability string and `accessOps` entries are validated only
 * as non-empty strings. This policy grants exactly one bespoke ability,
 * {@see self::ABILITY}, which only the dashboard's own listings ask for, and
 * returns neutral for `view` and for everything else.
 *
 * Net effect, asserted route by route in NoAutoExposureTest:
 *   - dashboard listings resolve          (they ask for monitor.dashboard_read)
 *   - MCP entity.read / entity.search     -> check `view` -> Neutral -> denied
 *   - GraphQL                             -> checks `view` -> denied
 *   - /api/discovery/*                    -> checks `view` -> denied
 *   - SSR /{type}/{id}                    -> N/A, no monitor type is group:'content'
 *   - JSON:API /api/{type}                -> route absent, `api` stays false
 *
 * ## Deliberately absent
 *
 * There is no `SUPPORTS_LISTING_FAST_PATH` constant. That prohibition is
 * belt-and-braces rather than load-bearing —
 * `ListingResolver::canUseAccessFastPath()` returns false whenever `accessOps`
 * differs from the default `['view']`, before it ever probes the constant, so
 * on these listings the fast path is structurally unreachable. It is asserted
 * anyway because it costs nothing and documents intent.
 *
 * @api
 */
#[PolicyAttribute(entityType: MonitorEntityTypes::ALL)]
final class MonitorDashboardAccessPolicy implements AccessPolicyInterface
{
    /**
     * The only ability this policy ever grants.
     *
     * Deliberately not `view`: granting `view` would simultaneously open MCP,
     * GraphQL, Discovery and SSR for every monitor entity.
     */
    public const string ABILITY = 'monitor.dashboard_read';

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === self::ABILITY) {
            return AccessResult::allowed('Monitor dashboard read: the projection is the boundary.');
        }

        // Everything else, INCLUDING `view`, `update`, `delete`: Neutral, which
        // entity-level access treats as a denial. This is the line that keeps
        // the generic surfaces shut.
        return AccessResult::neutral('Monitor entities are reachable only through the dashboard projection.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // Creation is a collector and CLI concern, never a request-time one.
        return AccessResult::neutral('Monitor entities are not created through request-time access.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, MonitorEntityTypes::ALL, true);
    }
}
