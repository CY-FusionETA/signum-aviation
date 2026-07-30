<?php
/**
 * Forget the stored invoice link for a trip so it can be re-created.
 * Use after you've deleted the draft invoice in Xero and want Unidash to let you
 * raise it again (e.g. to re-check the format). Only clears Unidash's record —
 * it does NOT touch Xero.
 *
 *   php cli/uninvoice.php <trip_number>
 *   php cli/uninvoice.php 99751
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Repo\TripRepo;
use App\Repo\InvoiceRepo;
use App\Service\Xero\XeroOAuth;

$tripNumber = trim((string)($argv[1] ?? ''));
if ($tripNumber === '') {
    fwrite(STDERR, "Usage: php cli/uninvoice.php <trip_number>\n");
    exit(1);
}
if (!XeroOAuth::isConnected()) {
    fwrite(STDERR, "Xero not connected — can't resolve the tenant.\n");
    exit(1);
}
$tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');

$trip = null;
foreach (TripRepo::all() as $t) {
    if ((string)$t['trip_number'] === $tripNumber) { $trip = $t; break; }
}
if (!$trip) {
    fwrite(STDERR, "No trip {$tripNumber} in the master list.\n");
    exit(1);
}

$n = InvoiceRepo::deleteByTrip($tenantId, (int)$trip['id']);
echo $n > 0
    ? "Cleared the invoice link for trip {$tripNumber} — it's ready to invoice again in the UI.\n"
    : "Trip {$tripNumber} had no stored invoice link (nothing to clear).\n";
