<?php
/**
 * Headless Module 3 reconcile — pull draft bills from Xero and match them to
 * trips. Safe to run on a cron; uses the DB-stored Xero tokens (refreshed
 * automatically), no browser/session needed.
 *
 *   php cli/reconcile-bills.php                 # pull + match only (review/tag in the UI)
 *   php cli/reconcile-bills.php --tag           # also tag every matched bill in Xero
 *
 * Client invoices are NOT created here — they're raised only when you Approve a
 * trip's bills in the UI (Bills page).
 *
 * Cron (every 15 min), on the droplet — use a step or explicit minutes, e.g.
 *   0,15,30,45 * * * * cd /var/www/signum-aviation && php cli/reconcile-bills.php >> storage/logs/reconcile.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Service\Bills\BillReconciler;
use App\Service\Xero\XeroOAuth;

$stamp = date('Y-m-d H:i:s');
if (!XeroOAuth::isConnected()) {
    fwrite(STDERR, "[{$stamp}] Xero not connected — nothing to reconcile.\n");
    exit(1);
}

$res = BillReconciler::refresh();
if (empty($res['ok'])) {
    fwrite(STDERR, "[{$stamp}] refresh failed: " . ($res['error'] ?? 'unknown') . "\n");
    exit(1);
}
$s = $res['summary'];
echo "[{$stamp}] pulled={$s['pulled']} matched={$s['matched']} ambiguous={$s['ambiguous']} review={$s['review']} tagged={$s['tagged']} approved={$s['approved']} retired={$s['retired']}\n";

// Optional: auto-tag matched bills (writes the trip number to Xero). Off by
// default — leave tagging as a human confirm unless you pass --tag.
if (in_array('--tag', $argv, true)) {
    $t = BillReconciler::tagAllMatched();
    echo "[{$stamp}] auto-tagged={$t['tagged']} failed={$t['failed']}\n";
}
