<?php
/**
 * Turn the login page's one-click "Continue as <Name>" button on or off.
 *
 *   php cli/quick-login.php on       # show the button
 *   php cli/quick-login.php off      # hide it (password required)
 *   php cli/quick-login.php status
 *   php cli/quick-login.php name Simon    # override the button label
 *
 * SECURITY: while this is on, anyone who can reach the login page can click the
 * button and get in without the password. Only leave it on if the URL is not
 * worth protecting, or keep it off and type the password.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;

$cmd = strtolower(trim((string)($argv[1] ?? 'status')));

switch ($cmd) {
    case 'on':
        Settings::set('auth.quick_login', '1');
        echo "Quick sign-in: ON — anyone reaching the login page can sign in without the password.\n";
        break;
    case 'off':
        Settings::set('auth.quick_login', '0');
        echo "Quick sign-in: OFF — password required.\n";
        break;
    case 'name':
        $n = trim((string)($argv[2] ?? ''));
        if ($n === '') { fwrite(STDERR, "Usage: php cli/quick-login.php name <label>\n"); exit(1); }
        Settings::set('auth.display_name', $n);
        echo "Button label set to: {$n}\n";
        break;
    case 'status':
        $on = (string)Settings::get('auth.quick_login', '0') === '1';
        echo "Quick sign-in: " . ($on ? "ON" : "OFF") . "\n";
        echo "Button label:  " . ((string)Settings::get('auth.display_name', '') ?: '(derived from email)') . "\n";
        break;
    default:
        fwrite(STDERR, "Usage: php cli/quick-login.php on|off|status|name <label>\n");
        exit(1);
}
