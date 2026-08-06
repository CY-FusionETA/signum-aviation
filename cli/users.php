<?php
/**
 * Inspect and manage sign-in accounts.
 *
 *   php cli/users.php list
 *   php cli/users.php role <email> superadmin|user
 *   php cli/users.php delete <email>
 *
 * Create accounts / change passwords with cli/set-admin.php.
 * The last superadmin cannot be deleted or demoted — without one, nobody can
 * reach the access log and only the CLI could put it back.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Service\Auth\Users;

$cmd   = strtolower(trim((string)($argv[1] ?? 'list')));
$email = strtolower(trim((string)($argv[2] ?? '')));

switch ($cmd) {
    case 'list':
        $rows = Users::all();
        if (!$rows) { echo "No accounts yet. Create one with: php cli/set-admin.php <email> <password> [role]\n"; break; }
        printf("%-34s %-12s %-10s %s\n", 'EMAIL', 'ROLE', 'NAME', 'ACCESS LOG');
        foreach ($rows as $u) {
            printf("%-34s %-12s %-10s %s\n", $u['email'], $u['role'], (string)$u['display_name'],
                Users::canViewAccessLog((string)$u['role']) ? 'yes' : 'no');
        }
        $demo = Users::quickLoginUser();
        echo "\nQuick-login button signs in as: " . ($demo ? $demo['email'] : '(none configured)') . "\n";
        break;

    case 'role':
        $role = strtolower(trim((string)($argv[3] ?? '')));
        if ($email === '' || !in_array($role, [Users::SUPERADMIN, Users::USER], true)) {
            fwrite(STDERR, "Usage: php cli/users.php role <email> superadmin|user\n"); exit(1);
        }
        $u = Users::find($email);
        if (!$u) { fwrite(STDERR, "No such account: {$email}\n"); exit(1); }
        if ($u['role'] === Users::SUPERADMIN && $role === Users::USER && Users::superadminCount() <= 1) {
            fwrite(STDERR, "Refusing: {$email} is the only superadmin. Promote another account first.\n"); exit(1);
        }
        Users::setRole($email, $role);
        echo "{$email} is now {$role} — " . ($role === Users::SUPERADMIN ? 'can' : 'cannot') . " view the access log.\n";
        break;

    case 'delete':
        if ($email === '') { fwrite(STDERR, "Usage: php cli/users.php delete <email>\n"); exit(1); }
        if (!Users::find($email)) { fwrite(STDERR, "No such account: {$email}\n"); exit(1); }
        if (!Users::delete($email)) {
            fwrite(STDERR, "Refusing: {$email} is the only superadmin. Promote another account first.\n"); exit(1);
        }
        echo "Deleted {$email}\n";
        break;

    default:
        fwrite(STDERR, "Usage: php cli/users.php list|role <email> <role>|delete <email>\n");
        exit(1);
}
