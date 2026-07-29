<?php
/** Assertion tests for Skyledger Module 4. Run: php tests/run_tests.php */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Settings;
use App\Repo\TripRepo;
use App\Service\Leon\LeonParser;
use App\Service\Leon\LeonProcessor;
use App\Service\Xero\XeroApiClient;

$pass = 0; $fail = 0;
function check(string $label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label,
        $ok ? '' : "  (got " . var_export($got, true) . ", want " . var_export($want, true) . ")");
}

foreach (glob(STORAGE_ROOT . '/skyledger.sqlite*') as $f) @unlink($f);
Db::conn()->exec(file_get_contents(__DIR__ . '/../db/schema.sql'));
$FX = __DIR__ . '/fixtures';

// --- 1. CSV parser: counts + column-order independence + edge cases -
$inc = LeonParser::parse("$FX/flight_count_inc.csv");
$ltd = LeonParser::parse("$FX/flight_count_ltd.csv");
check('inc trip count = 17 (∑ row skipped)', count($inc['trips']), 17);
check('ltd trip count = 7', count($ltd['trips']), 7);

$byTrip = []; foreach ($inc['trips'] as $t) $byTrip[$t['trip_number']] = $t;
check('blank client -> empty string', $byTrip['35524']['client_name'], '');
check('alpha trip number preserved (KZ2OS4)', isset($byTrip['KZ2OS4']), true);
check('dd-mm-yyyy -> ISO start', $byTrip['35507']['start_date'], '2026-07-21');
check('cross-month end date parsed', $byTrip['34715']['end_date'], '2026-08-11');
check('flights_count is int', $byTrip['35507']['flights_count'], 2);
$ltdByTrip = []; foreach ($ltd['trips'] as $t) $ltdByTrip[$t['trip_number']] = $t;
check('ltd column order: aircraft mapped', $ltdByTrip['35503']['aircraft'], 'M-DIVE');
check('ltd column order: client mapped', $ltdByTrip['35503']['client_name'], 'ClemAir Limited');
check('ltd slash trip number preserved', isset($ltdByTrip['07-2026/76']), true);

// --- 2. PDF parser matches CSV field-for-field (both column orders) --
foreach (['inc', 'ltd'] as $ent) {
    $csv = LeonParser::parse("$FX/flight_count_{$ent}.csv");
    $pdf = LeonParser::parse("$FX/flight_count_{$ent}.pdf");
    check("$ent: PDF source flag", $pdf['source'], 'pdf');
    check("$ent: PDF trip count == CSV", count($pdf['trips']), count($csv['trips']));
    $cm = []; foreach ($csv['trips'] as $t) $cm[$t['trip_number']] = $t;
    $diffs = 0;
    foreach ($pdf['trips'] as $pt) {
        $ct = $cm[$pt['trip_number']] ?? null;
        if (!$ct) { $diffs++; continue; }
        foreach (['client_name','aircraft','route','start_date','end_date','flights_count'] as $f) {
            if ((string)$ct[$f] !== (string)$pt[$f]) $diffs++;
        }
    }
    check("$ent: PDF fields identical to CSV", $diffs, 0);
}

// --- 2b. XLSX parser matches CSV field-for-field (incl date serials) -
foreach (['inc', 'ltd'] as $ent) {
    if (!is_file("$FX/flight_count_{$ent}.xlsx")) { check("$ent: xlsx fixture present", false, true); continue; }
    $csv = LeonParser::parse("$FX/flight_count_{$ent}.csv");
    $xls = LeonParser::parse("$FX/flight_count_{$ent}.xlsx");
    check("$ent: XLSX source flag", $xls['source'], 'xlsx');
    check("$ent: XLSX trip count == CSV", count($xls['trips']), count($csv['trips']));
    $cm = []; foreach ($csv['trips'] as $t) $cm[$t['trip_number']] = $t;
    $diffs = 0;
    foreach ($xls['trips'] as $pt) {
        $ct = $cm[$pt['trip_number']] ?? null;
        if (!$ct) { $diffs++; continue; }
        foreach (['client_name','aircraft','route','start_date','end_date','flights_count'] as $f) {
            if ((string)$ct[$f] !== (string)$pt[$f]) $diffs++;
        }
    }
    check("$ent: XLSX fields identical to CSV", $diffs, 0);
}

// --- 3. PO payload shape (trip-metadata-only, DRAFT, desc-only line) -
$payload = XeroApiClient::buildOrderPayload($byTrip['35518']);
check('PO status DRAFT', $payload['Status'], 'DRAFT');
check('PO number = trip number', $payload['PurchaseOrderNumber'], '35518');
check('PO date = start', $payload['Date'], '2026-07-21');
check('PO delivery = end', $payload['DeliveryDate'], '2026-07-22');
check('single description-only line', count($payload['LineItems']), 1);
check('line has no amount (metadata only)', isset($payload['LineItems'][0]['UnitAmount']), false);
check('currency omitted when unset', isset($payload['CurrencyCode']), false);
check('blank client -> placeholder contact', XeroApiClient::contactName($byTrip['35524']), 'Unknown Client (LEON)');
$paid = XeroApiClient::buildOrderPayload(array_merge($byTrip['35518'], ['currency' => 'USD']));
check('currency stamped when set', $paid['CurrencyCode'], 'USD');

// --- 4. import() builds the master list without creating POs ---------
$imp = LeonProcessor::import("$FX/flight_count_inc.csv", 'inc');
check('import parsed count', $imp['summary']['parsed'], 17);
check('import marks all new first time', $imp['summary']['new'], 17);
check('master list persisted', count(TripRepo::all()), 17);
$imp2 = LeonProcessor::import("$FX/flight_count_inc.csv", 'inc');
check('re-import updates, no dupes', $imp2['summary']['updated'], 17);
check('still 17 rows (idempotent upsert)', count(TripRepo::all()), 17);

// --- 5. createPosForIds dry-run (stub) creates nothing but shows payload
$ids = array_map(fn($t) => (int)$t['id'], array_slice(TripRepo::all(), 0, 3));
$res = LeonProcessor::createPosForIds($ids, true);
check('dry-run over 3 selected', $res['summary']['dry_run'], 3);
check('dry-run creates nothing', $res['summary']['created'], 0);
check('dry-run row carries payload', isset($res['rows'][0]['payload']['Status']), true);

// --- 6. Tenant-switch clearing (reconnection like Starship) ----------
$id = Db::insert('leon_trips', ['entity'=>'inc','trip_number'=>'TESTX','tenant_id'=>'OLD','xero_po_id'=>'po-old','xero_po_number'=>'TESTX']);
Db::q("UPDATE leon_trips SET xero_po_id=NULL, xero_po_number=NULL, xero_synced_at=NULL, xero_last_error=NULL WHERE xero_po_id IS NOT NULL");
check('tenant switch clears xero_po_id', Db::one("SELECT xero_po_id FROM leon_trips WHERE id=?", [$id])['xero_po_id'], null);
Db::q("DELETE FROM leon_trips WHERE id=?", [$id]);

// --- 7. Idempotency: trip already synced to CURRENT tenant is skipped
Settings::set('xero.client_id','dummy'); Settings::set('xero.client_secret','dummy'); Settings::set('xero.enabled','1');
Db::insert('oauth_tokens', ['provider'=>'xero','tenant_id'=>'ORG-A','tenant_name'=>'Org A','access_token'=>'x','refresh_token'=>'y','expires_at'=>date('Y-m-d H:i:s', time()+3600),'scope'=>'z']);
$t0 = TripRepo::all()[0];
Db::q("UPDATE leon_trips SET tenant_id='ORG-A', xero_po_id='po-a-1', xero_po_number=trip_number WHERE id=?", [(int)$t0['id']]);
$res = LeonProcessor::createPosForIds([(int)$t0['id']], false);   // matches current tenant -> skip, no HTTP
check('already-synced trip is skipped', $res['summary']['skipped'], 1);
check('no create/fail on skip', $res['summary']['created'] + $res['summary']['failed'], 0);
check('hasPoInTenant true for current org', TripRepo::hasPoInTenant(TripRepo::findById((int)$t0['id']), 'ORG-A'), true);
check('hasPoInTenant false for other org', TripRepo::hasPoInTenant(TripRepo::findById((int)$t0['id']), 'ORG-B'), false);

echo "\n" . str_repeat('=', 40) . "\n";
printf("TOTAL: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
