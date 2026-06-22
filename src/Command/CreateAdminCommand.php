<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\AdminRoles;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

/**
 * `vendor/bin/waaseyaa app:create-admin <email> [--name=] [--password=]`
 *
 * Creates (or updates) the administrator account that holds the admin dashboards
 * at /admin/anokii and /admin/analytics. The account is given the framework
 * `administrator` role (via {@see AdminRoles}, which stamps ACCESS_ADMIN), so the
 * AdminController gate admits it.
 *
 * The password is NEVER hardcoded: it is read from --password, or from the
 * RHTCIRCLE_ADMIN_PASSWORD environment variable (set from the vault / the
 * container secrets file), and stored only as a hash via User::setRawPassword().
 * The command refuses to run without one. Idempotent: re-running updates the
 * password and re-affirms the role.
 */
final class CreateAdminCommand
{
    public function __construct(private readonly EntityTypeManager $entityTypeManager) {}

    public function run(SymfonyCommandIO $io): int
    {
        $email = strtolower(trim((string) $io->argument('email')));
        if ($email === '' || !str_contains($email, '@')) {
            $io->error('Provide a valid email: app:create-admin <email> [--name=...] [--password=...]');

            return 1;
        }

        $password = (string) ($io->option('password') ?? '');
        if ($password === '') {
            $password = (string) (getenv('RHTCIRCLE_ADMIN_PASSWORD') ?: '');
        }
        if ($password === '') {
            $io->error('No password given. Pass --password=... or set RHTCIRCLE_ADMIN_PASSWORD (from the vault). The password is never hardcoded.');

            return 1;
        }
        if (mb_strlen($password) < 12) {
            $io->error('Password too short: use at least 12 characters.');

            return 1;
        }

        $name = (string) ($io->option('name') ?? '');
        $roles = new AdminRoles();

        try {
            $storage = $this->entityTypeManager->getStorage('user');
            $user = $storage->loadByKey('mail', $email);

            if (!$user instanceof User) {
                $user = $storage->create(['name' => $name !== '' ? $name : $email, 'mail' => $email, 'status' => 1]);
                $created = true;
            } else {
                $created = false;
                if ($name !== '') {
                    $user = $user->setName($name);
                }
            }

            // Stamp the administrator role (+ ACCESS_ADMIN) and the password hash.
            // Each setter returns a new instance; persist the final one.
            $user = $roles->apply($user, AdminRoles::ROLE_ADMIN);
            $user = $user->setRawPassword($password);
            $storage->save($user);
        } catch (\Throwable $e) {
            $io->error('Failed to create admin: ' . $e->getMessage());

            return 1;
        }

        $io->writeln(sprintf(
            '%s admin account %s (uid %s) with the administrator role. Sign in at /admin/login.',
            $created ? 'Created' : 'Updated',
            $email,
            (string) $user->id(),
        ));

        return 0;
    }
}
