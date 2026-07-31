<?php
/** Assertion tests for Skyledger Module 4. Run: php tests/run_tests.php */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Settings;
use App\Repo\TripRepo;
use App\Service\Leon\LeonParser;
use App\Service\Leon\LeonProcessor;

$pass = 0; $fail = 0;
function check(string $label, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label,
        $ok ? '' : "  (got " . var_export($got, true) . ", want " . var_export($want, true) . ")");
}

// Tests run against a throwaway DB, NEVER the live one. Db::conn() is a lazy
// singleton that reads cfg('db.path'), so redirecting it here — before the
// first connection — keeps storage/skyledger.sqlite untouched even when the
// suite is run from a deployed checkout.
$TEST_DB = sys_get_temp_dir() . '/skyledger-test-' . getmypid() . '.sqlite';
$dropTestDb = function () use ($TEST_DB) {
    foreach (glob($TEST_DB . '*') as $f) @unlink($f);   // incl. -wal / -shm
};
$GLOBALS['config']['db']['path'] = $TEST_DB;
$dropTestDb();
register_shutdown_function($dropTestDb);

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

// --- 4. import() builds the master list (creates nothing in Xero) ----
$imp = LeonProcessor::import("$FX/flight_count_inc.csv", 'inc');
check('import parsed count', $imp['summary']['parsed'], 17);
check('import marks all new first time', $imp['summary']['new'], 17);
check('master list persisted', count(TripRepo::all()), 17);
$imp2 = LeonProcessor::import("$FX/flight_count_inc.csv", 'inc');
check('re-import updates, no dupes', $imp2['summary']['updated'], 17);
check('still 17 rows (idempotent upsert)', count(TripRepo::all()), 17);

// --- 8. Module 3: match a supplier bill to a trip -------------------
TripRepo::upsert(['trip_number'=>'99751','client_name'=>'Signum Malaysia S/B','aircraft'=>'MAL191','route'=>'VHHH','start_date'=>'2026-03-26','end_date'=>'2026-03-30','flights_count'=>2], 'inc', 'test');
$mtrips = TripRepo::all();
$M = '\App\Service\Bills\BillMatcher';

$b1 = $M::match(['description'=>'Ground Handling at VHHH on 30/03/2026 for MAL191','reference'=>''], $mtrips);
check('bill matched by tail+date+airport', $b1['status'], 'matched');
check('  → correct trip (99751)', $b1['trip']['trip_number'] ?? '', '99751');
check('  → extracted tail', $b1['ex_tail'], 'MAL191');
check('  → extracted airport', $b1['ex_airport'], 'VHHH');
check('  → extracted date → ISO', $b1['ex_date'], '2026-03-30');

$b2 = $M::match(['description'=>'Landing, Handling & Associated Charges at EGGW on 23/07/2026 for N700LE'], $mtrips);
check('service date narrows shared tail to one trip', $b2['status'], 'matched');
check('  → trip 35528 (23 Jul)', $b2['trip']['trip_number'] ?? '', '35528');

$b3 = $M::match(['description'=>'Monthly consulting services'], $mtrips);
check('unrelated bill → review', $b3['status'], 'review');

$b4 = $M::match(['description'=>'Handling charges','reference'=>'Trip 99751'], $mtrips);
check('explicit trip number in reference → matched', $b4['status'], 'matched');
check('  → trip 99751', $b4['trip']['trip_number'] ?? '', '99751');

$b5 = $M::match(['description'=>'Handling at EGGW on 01/01/2026 for N700LE'], $mtrips);
check('same tail+airport, out-of-window date, 2 trips → ambiguous', $b5['status'], 'ambiguous');

// Tolerant: free-form description (no standard phrasing) still matches by tail.
$b6 = $M::match(['description'=>'Ground handling services for aircraft MAL191 at Hong Kong (VHHH)'], $mtrips);
check('free-form desc matches by tail scan', $b6['status'], 'matched');
check('  → trip 99751 via tail', $b6['trip']['trip_number'] ?? '', '99751');
check('  → ex_tail backfilled from trip', $b6['ex_tail'], 'MAL191');

// Manual assign overrides a review bill.
$rev = $M::match(['description'=>'Consulting fee'], $mtrips);
$brev = \App\Repo\BillRepo::upsert('T2', ['invoice_id'=>'rev-1','supplier'=>'X'], $rev);
check('unmatched bill stored as review', $brev['match_status'], 'review');
$t99 = null; foreach (TripRepo::all() as $tt) if ($tt['trip_number']==='99751') $t99 = $tt;
\App\Service\Bills\BillReconciler::assign((int)$brev['id'], (int)$t99['id']);
$brev2 = \App\Repo\BillRepo::findById((int)$brev['id']);
check('manual assign sets matched trip', $brev2['matched_trip_number'], '99751');
check('manual assign flips status to matched', $brev2['match_status'], 'matched');

// BillRepo persistence
$brow = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-1','supplier'=>'ASA South China Ltd','description'=>'Ground Handling at VHHH on 30/03/2026 for MAL191'], $b1);
check('bill upsert stores matched trip', $brow['matched_trip_number'], '99751');
check('bill upsert stores client', $brow['matched_client'], 'Signum Malaysia S/B');
check('bill upsert status', $brow['match_status'], 'matched');
$brow2 = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-1'], $b1);
check('re-upsert same invoice: no duplicate', count(\App\Repo\BillRepo::allForTenant('T1')), 1);

// A later re-match that finds nothing must keep the existing trip link —
// otherwise the 15-min cron silently unlinks manual assigns and tagged bills.
$noMatch = $M::match(['description'=>'Consulting fee'], $mtrips);
$bkeep = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-1'], $noMatch);
check('re-match with no trip keeps the link', $bkeep['matched_trip_number'], '99751');
check('  → status not downgraded to review', $bkeep['match_status'], 'matched');
\App\Repo\BillRepo::markTagged((int)$bkeep['id']);
$btag = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-1'], $noMatch);
check('tagged bill survives a no-trip re-match', $btag['match_status'], 'tagged');
check('  → still in tagged() for invoicing', count(\App\Repo\BillRepo::tagged('T1')), 1);

// reconcile without a Xero connection fails cleanly
\App\Service\Xero\XeroOAuth::disconnect();
$rr = \App\Service\Bills\BillReconciler::refresh();
check('refresh without Xero → ok=false', $rr['ok'], false);

// --- 9. Module 5: build a client invoice from tagged bills ----------
$IB = '\App\Service\Invoices\InvoiceBuilder';
$ib_bills = [
    ['total'=>100,'currency'=>'USD','description'=>'Ground Handling at VHHH on 30/03/2026 for MAL191','supplier'=>'ASA','ex_airport'=>'VHHH','ex_date'=>'2026-03-30','ex_tail'=>'MAL191'],
    ['total'=>50, 'currency'=>'USD','description'=>'Landing','supplier'=>'X'],
];
$bd = $IB::build($ib_bills, ['markup'=>1.02,'admin_pct'=>11,'support_fee'=>0,'account_code'=>'']);
check('invoice buildable (single currency)', $bd['buildable'], true);
check('invoice currency', $bd['currency'], 'USD');
check('recharge subtotal (×1.02)', number_format($bd['subtotal'],2), '153.00');
check('admin 11%', number_format($bd['admin'],2), '16.83');
check('invoice total', number_format($bd['total'],2), '169.83');
check('lines = 2 recharge + admin', count($bd['lines']), 3);
// Client-facing line is a concise summary (station/date/tail), NOT the raw bill blob.
check('recharge line summarised, not raw desc', $bd['lines'][0]['Description'], 'Ground handling & services at VHHH on 30/03/2026 (MAL191)');
check('  → wrong: does not carry the bill blob', strpos((string)$bd['lines'][0]['Description'], 'Ground Handling at VHHH on 30/03/2026 for MAL191'), false);
check('  → supplier name not leaked to client', strpos((string)$bd['lines'][0]['Description'], 'ASA'), false);
check('no-extraction bill → generic label', $bd['lines'][1]['Description'], 'Ground handling & associated services');

$bd2 = $IB::build($ib_bills, ['markup'=>1.0,'admin_pct'=>11,'support_fee'=>650,'account_code'=>'200']);
check('support fee adds a line', count($bd2['lines']), 4);
check('account code applied to line', $bd2['lines'][0]['AccountCode'] ?? '', '200');

// Legacy fallback: bills with no base fields + differing currencies can't be summed.
$bdm = $IB::build([['total'=>100,'currency'=>'USD'],['total'=>50,'currency'=>'EUR']], ['markup'=>1.02]);
check('mixed-currency bills (no base) → not buildable', $bdm['buildable'], false);

// Currency conversion: foreign bills carry base_total/base_currency from Xero, so
// USD + EUR bills recharge together in the org base currency (MYR).
$fx = [
    ['total'=>1000,'currency'=>'USD','currency_rate'=>4.7,'base_currency'=>'MYR','base_total'=>4700,'ex_airport'=>'VHHH','ex_date'=>'2026-03-30','ex_tail'=>'MAL191'],
    ['total'=>100, 'currency'=>'EUR','currency_rate'=>5.0,'base_currency'=>'MYR','base_total'=>500],
];
$bfx = $IB::build($fx, ['markup'=>1.0,'admin_pct'=>0,'support_fee'=>0,'account_code'=>'']);
check('foreign bills convert to org base currency', $bfx['currency'], 'MYR');
check('  → recharge sums the converted (base) amounts', number_format($bfx['subtotal'],2), '5,200.00');
check('  → line amount uses base_total not the foreign total', $bfx['lines'][0]['UnitAmount'], 4700.0);
check('  → mixed source currencies now buildable via base', $bfx['buildable'], true);

check('invoice reference format', $IB::reference(['aircraft'=>'MAL191','end_date'=>'2026-03-30','trip_number'=>'99751']), 'MAL191 2026-03-30 99751');

// readyTrips picks up a trip once its bill is tagged
$t99 = null; foreach (TripRepo::all() as $tt) if ($tt['trip_number']==='99751') $t99 = $tt;
$tb = \App\Repo\BillRepo::upsert('DEMO', ['invoice_id'=>'d1','supplier'=>'ASA','total'=>100,'currency'=>'USD','description'=>'Ground Handling at VHHH on 30/03/2026 for MAL191'],
        \App\Service\Bills\BillMatcher::match(['description'=>'Ground Handling at VHHH on 30/03/2026 for MAL191'], TripRepo::all()));
\App\Repo\BillRepo::markTagged((int)$tb['id']);
$ready = \App\Service\Invoices\InvoiceService::readyTrips('DEMO');
check('ready-to-invoice includes the tagged trip', count($ready), 1);
check('  → trip 99751', $ready[0]['trip']['trip_number'] ?? '', '99751');
check('  → build is buildable', $ready[0]['build']['buildable'], true);
check('  → not yet approved (tagged only)', $ready[0]['approved'], false);

// Approval gate: forTrip + markApproved flip the trip to fully approved.
$tripId99 = (int)$ready[0]['trip']['id'];
check('forTrip returns the linked bill', count(\App\Repo\BillRepo::forTrip('DEMO', $tripId99)), 1);
\App\Repo\BillRepo::markApproved((int)$tb['id']);
$readyA = \App\Service\Invoices\InvoiceService::readyTrips('DEMO');
check('after approving every bill → approved flag true', $readyA[0]['approved'], true);
check('  → bill status is approved', (string)\App\Repo\BillRepo::findById((int)$tb['id'])['match_status'], 'approved');
check('  → approved bill still appears in readyTrips', count($readyA), 1);

// Un-invoice: forgetting the link lets a trip be raised again.
$IR = '\App\Repo\InvoiceRepo';
$IR::store('DEMO', $ready[0]['trip'], $ready[0]['build'], 'xero-1', 'INV-001');
check('trip shows invoiced after store', !empty($IR::findByTrip('DEMO', (int)$ready[0]['trip']['id'])), true);
check('deleteByTrip removes the link', $IR::deleteByTrip('DEMO', (int)$ready[0]['trip']['id']), 1);
check('trip re-invoiceable after delete', $IR::findByTrip('DEMO', (int)$ready[0]['trip']['id']), null);

// Retire bills no longer active in Xero (voided/deleted just vanish).
check('retireMissing keeps a still-active bill', \App\Repo\BillRepo::retireMissing('DEMO', ['d1']), 0);
check('  → bill still present', !empty(\App\Repo\BillRepo::findById((int)$tb['id'])), true);
check('retireMissing removes a bill gone from Xero', \App\Repo\BillRepo::retireMissing('DEMO', ['some-other-id']), 1);
check('  → bill removed locally', \App\Repo\BillRepo::findById((int)$tb['id']), null);

// --- 10. Module 5: completeness gate (route legs vs tagged bills) ----
$CC = '\App\Service\Invoices\CompletenessChecker';

// Every route airport has a bill → complete.
$cc1 = $CC::check(
    ['route' => 'VHHH - RJTT - VHHH'],
    [['ex_airport'=>'VHHH','description'=>'Handling at VHHH'],
     ['ex_airport'=>'RJTT','description'=>'Handling at RJTT']]
);
check('all legs covered → complete', $cc1['status'], 'complete');
check('  → distinct leg count (VHHH once)', $cc1['legs'], 2);
check('  → nothing missing', $cc1['missing'], []);

// One route airport with no bill → gaps, and it is named.
$cc2 = $CC::check(
    ['route' => 'VHHH - RJTT - RKSI'],
    [['ex_airport'=>'VHHH','description'=>'Handling at VHHH']]
);
check('uncovered leg → gaps', $cc2['status'], 'gaps');
check('  → missing legs named', $cc2['missing'], ['RJTT','RKSI']);
check('  → covered legs named', $cc2['covered'], ['VHHH']);

// Airport found via free-form description (not ex_airport) still counts.
$cc3 = $CC::check(
    ['route' => 'VHHH - RJTT'],
    [['ex_airport'=>'','description'=>'Ground handling at Hong Kong VHHH and Tokyo RJTT']]
);
check('leg matched via description blob → complete', $cc3['status'], 'complete');

// No route on the trip → unknown (can't gate).
$cc4 = $CC::check(['route' => ''], [['ex_airport'=>'VHHH','description'=>'x']]);
check('no route → unknown', $cc4['status'], 'unknown');
check('  → zero legs', $cc4['legs'], 0);

// ZZZZ placeholders are not real legs.
$cc5 = $CC::check(['route' => 'VHHH - ZZZZ'], [['ex_airport'=>'VHHH','description'=>'x']]);
check('ZZZZ placeholder ignored → complete on real legs', $cc5['status'], 'complete');
check('  → ZZZZ not counted as a leg', $cc5['legs'], 1);

// The DEMO trip 99751 (route VHHH) with its tagged VHHH bill → complete.
check('readyTrips carries completeness', $ready[0]['complete']['status'] ?? '', 'complete');

echo "\n" . str_repeat('=', 40) . "\n";
printf("TOTAL: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
