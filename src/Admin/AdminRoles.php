<?php

declare(strict_types=1);

namespace App\Admin;

use Anokii\Access\AbstractWorkspaceRoles;

/**
 * The admin role model for rhtcircle, mapped onto the framework's role and
 * permission substrate via the shared Anokii base ({@see AbstractWorkspaceRoles}).
 * No parallel auth system: the base derives apply(), the framework Role value
 * objects (ProvidesRolesInterface, exposed by AppServiceProvider), the permission
 * union, and the role accessors from the single roleDefinitions() below.
 *
 * Two roles:
 *   - Admin    -> the framework's built-in `administrator` role, which short-
 *                 circuits every permission check (so it holds ACCESS_ADMIN too).
 *                 This is the role the single operator account is given.
 *   - Operator -> a non-admin role granting only ACCESS_ADMIN, for a future
 *                 dashboard-only account that should reach /admin/anokii and
 *                 /admin/analytics without full administrator power.
 *
 * The gate ({@see AdminController}) checks {@see ACCESS_ADMIN}: the administrator
 * role passes by short-circuit, an operator passes because the base apply() (and
 * the framework `user:assign-role` command) stamps the permission string onto the
 * user. Anyone else is refused.
 */
final class AdminRoles extends AbstractWorkspaceRoles
{
    /** Reuses the framework all-permissions role for the single operator account. */
    public const string ROLE_ADMIN = self::ROLE_ADMINISTRATOR;

    /** Dashboard-only role (no full administrator power). */
    public const string ROLE_OPERATOR = 'rht-operator';

    /** The permission the admin dashboards require. */
    public const string ACCESS_ADMIN = 'access rht admin';

    /**
     * @return array<string, array{label: string, permissions: list<string>, weight?: int}>
     */
    protected function roleDefinitions(): array
    {
        return [
            self::ROLE_ADMIN => [
                'label' => 'Administrator',
                'permissions' => [self::ACCESS_ADMIN],
                'weight' => 0,
            ],
            self::ROLE_OPERATOR => [
                'label' => 'RHT Operator (dashboards only)',
                'permissions' => [self::ACCESS_ADMIN],
                'weight' => 10,
            ],
        ];
    }
}
