<?php
/**
 * Set (or change) the Signum Unidash admin login — email + password.
 * Stores the email and a bcrypt hash in app_settings; the plaintext password
 * is never written to disk. Run on the server:
 *
 *   php cli/set-admin.php <email> <password>
 *
 * Example:
 *   php cli/set-admin.php simon@fusioneta.com 'fusionPass123!'
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;

$email = strtolower(trim((string)($argv[1] ?? '')));
$pass  = (string)($argv[2] ?? '');

if ($email === '' || $pass === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php cli/set-admin.php <email> <password>\n");
    exit(1);
}

Settings::set('auth.email', $email);
Settings::set('auth.password_hash', password_hash($pass, PASSWORD_DEFAULT));

echo "Admin login set: {$email}\n";
echo "You can now sign in at " . (cfg('app.base_url') ?: '(your base_url)') . " with this email + password.\n";
