<?php
/**
 * Create or update a Signum Unidash sign-in account.
 * Stores the email and a bcrypt hash in the users table; the plaintext
 * password is never written to disk. Run on the server:
 *
 *   php cli/set-admin.php <email> <password> [role] [display-name]
 *
 * role is 'superadmin' (everything, incl. the access log) or 'user'
 * (everything else). Defaults to 'user'.
 *
 * Examples:
 *   php cli/set-admin.php simon@fusioneta.com 'fusionPass123!' superadmin Simon
 *   php cli/set-admin.php signum@fusioneta.com demodemo123 user Signum
 *
 * Re-running with an existing email resets that account's password and role.
 * List what exists with: php cli/users.php list
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;
use App\Service\Auth\Users;

$email = strtolower(trim((string)($argv[1] ?? '')));
$pass  = (string)($argv[2] ?? '');
$role  = (string)($argv[3] ?? Users::USER);
$name  = (string)($argv[4] ?? '');

if ($email === '' || $pass === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php cli/set-admin.php <email> <password> [superadmin|user] [display-name]\n");
    exit(1);
}
if (!in_array(strtolower(trim($role)), [Users::SUPERADMIN, Users::USER], true)) {
    fwrite(STDERR, "Unknown role '{$role}'. Use 'superadmin' or 'user'.\n");
    exit(1);
}

$existed = Users::find($email) !== null;
Users::set($email, $pass, $role, $name);

// Keep the legacy single-admin key pointing at a real account, for any code
// path that has not been migrated off it.
if ((string)Settings::get('auth.email', '') === '') Settings::set('auth.email', $email);

echo ($existed ? "Updated" : "Created") . " account: {$email} (" . Users::normalizeRole($role) . ")\n";
echo Users::normalizeRole($role) === Users::SUPERADMIN
    ? "  → CAN view the access log.\n"
    : "  → cannot view the access log.\n";
echo "Sign in at " . (cfg('app.base_url') ?: '(your base_url)') . " with this email + password.\n";
