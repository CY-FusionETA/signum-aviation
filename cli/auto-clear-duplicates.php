<?php
/**
 * Kill-switch for the Inbox's automatic duplicate recovery.
 *
 *   php cli/auto-clear-duplicates.php status
 *   php cli/auto-clear-duplicates.php off     # only report duplicates
 *   php cli/auto-clear-duplicates.php on      # clear + re-send (default)
 *
 * When ON: a processor reply of "⚠️ Already exists in Xero" makes Unidash delete
 * the leftover DRAFT bill in Xero and send the same file for processing again, so
 * the bill is recreated without anyone emailing the invoice a second time.
 * Approved, paid and submitted bills are never touched, and each invoice is only
 * retried once. Turn it OFF to leave every duplicate for a human.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Settings;
use App\Service\Inbox\DuplicateBill;
use App\Service\Inbox\Wazzup;

$cmd = strtolower(trim((string)($argv[1] ?? 'status')));

switch ($cmd) {
    case 'on':
        Settings::set('inbox.auto_clear_duplicates', '1');
        echo "Automatic duplicate clearing: ON\n";
        break;
    case 'off':
        Settings::set('inbox.auto_clear_duplicates', '0');
        echo "Automatic duplicate clearing: OFF — duplicates are logged and left in Xero.\n";
        break;
    case 'status':
        echo 'Automatic duplicate clearing: ' . (DuplicateBill::enabled() ? "ON" : "OFF") . "\n";
        echo 'Re-sending over WhatsApp:     ' . (Wazzup::isConfigured()
            ? 'configured (channel ' . Wazzup::channelId() . ' → ' . Wazzup::processorNumber() . ")\n"
            : "NOT configured — set wazzup.api_key / channel_id / wazzocr_number\n");
        break;
    default:
        fwrite(STDERR, "Usage: php cli/auto-clear-duplicates.php on|off|status\n");
        exit(1);
}
