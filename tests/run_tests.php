<?php
/** Assertion tests for Skyledger Module 4. Run: php tests/run_tests.php */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Settings;
use App\Repo\TripRepo;
use App\Service\Leon\LeonParser;
use App\Service\Leon\LeonProcessor;
use App\Service\Auth\AccessLog;
use App\Service\Auth\Users;

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

// Approval hold: while set, a refresh must not mirror Xero's approval onto the bill,
// so approving it stays a deliberate click here. The flag survives the refresh upsert.
$bh = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-hold','status'=>'AUTHORISED'], $noMatch);
check('bill starts unheld', (int)$bh['approval_hold'], 0);
\App\Repo\BillRepo::setApprovalHold((int)$bh['id'], true);
check('approval hold set', (int)\App\Repo\BillRepo::findById((int)$bh['id'])['approval_hold'], 1);
$bh2 = \App\Repo\BillRepo::upsert('T1', ['invoice_id'=>'inv-hold','status'=>'AUTHORISED'], $noMatch);
check('  → survives a refresh upsert', (int)$bh2['approval_hold'], 1);
\App\Repo\BillRepo::setApprovalHold((int)$bh['id'], false);
check('  → released again', (int)\App\Repo\BillRepo::findById((int)$bh['id'])['approval_hold'], 0);

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

// Currency direction: Xero's CurrencyRate is bill-currency units per 1 base unit,
// so base = total / rate. A USD bill in an MYR org (rate < 1) → a LARGER MYR value.
$XA = '\App\Service\Xero\XeroApiClient';
check('base = total / rate (USD 100 @ 0.25 → MYR 400)', $XA::baseAmount(100.0, 0.25), 400.0);
check('  → base is larger than foreign when rate < 1', $XA::baseAmount(27506.98, 0.25) > 27506.98, true);
check('base bill (rate 1) unchanged', $XA::baseAmount(3200.0, 1.0), 3200.0);
check('zero/blank rate treated as 1 (no divide-by-zero)', $XA::baseAmount(100.0, 0.0), 100.0);
check('null total stays null', $XA::baseAmount(null, 0.25), null);

check('invoice reference format', $IB::reference(['aircraft'=>'MAL191','end_date'=>'2026-03-30','trip_number'=>'99751']), 'MAL191 2026-03-30 99751');

// Attachment filenames: bill-number prefix, de-collision, illegal-char strip.
$used = [];
check('attachment name prefixed with bill number', $XA::attachmentName('SN030473','invoice.pdf',$used), 'SN030473 - invoice.pdf');
check('  → different bill, same file: no collision', $XA::attachmentName('GH-037','invoice.pdf',$used), 'GH-037 - invoice.pdf');
$dup = [];
check('same-name first copy kept as-is', $XA::attachmentName('','scan.pdf',$dup), 'scan.pdf');
check('  → second copy de-collided before extension', $XA::attachmentName('','scan.pdf',$dup), 'scan (2).pdf');
check('  → third copy increments', $XA::attachmentName('','scan.pdf',$dup), 'scan (3).pdf');
$clean = [];
check('illegal path chars stripped', $XA::attachmentName('','a/b:c*d.pdf',$clean), 'a_b_c_d.pdf');
$empty = [];
check('empty name falls back', $XA::attachmentName('','',$empty), 'attachment');

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

// --- 11. Partial-trip handling: waive legs + force override ----------
// Waiving the un-billed legs drops them from "missing" and completes the trip.
$ccw = $CC::check(
    ['route' => 'VHHH - RJTT - RKSI', 'waived_legs' => '["RJTT","RKSI"]'],
    [['ex_airport'=>'VHHH','description'=>'Handling at VHHH']]
);
check('waived legs no longer block → complete', $ccw['status'], 'complete');
check('  → waived legs reported', $ccw['waived'], ['RJTT','RKSI']);
check('  → nothing missing after waive', $ccw['missing'], []);
check('  → covered still only the billed leg', $ccw['covered'], ['VHHH']);
// The Legs pill counts billed + waived, so a fully waived trip reads 3/3, not 0/3.
check('  → billed + waived accounts for every leg', count($ccw['covered']) + count($ccw['waived']), $ccw['legs']);
$ccAllW = $CC::check(['route' => 'KATW - LEPA - LEMH - LEPA - KATW', 'waived_legs' => '["LEPA","KATW","LEMH"]'], []);
check('every leg waived → complete', $ccAllW['status'], 'complete');
check('  → 3 distinct legs', $ccAllW['legs'], 3);
check('  → all 3 accounted for', count($ccAllW['covered']) + count($ccAllW['waived']), 3);

// Partial waive: one leg still un-waived and unbilled → still gaps.
$ccw2 = $CC::check(['route' => 'VHHH - RJTT - RKSI', 'waived_legs' => '["RJTT"]'],
    [['ex_airport'=>'VHHH','description'=>'Handling at VHHH']]);
check('one leg still un-waived + unbilled → gaps', $ccw2['status'], 'gaps');
check('  → only the un-waived leg missing', $ccw2['missing'], ['RKSI']);

// TripRepo waive parsing (JSON, comma, empty) + persisted toggle.
$TR = '\App\Repo\TripRepo';
check('waivedLegs parses JSON array', $TR::waivedLegs(['waived_legs'=>'["EGGW","VHHH"]']), ['EGGW','VHHH']);
check('waivedLegs parses comma string, uppercased', $TR::waivedLegs(['waived_legs'=>'eggw, vhhh']), ['EGGW','VHHH']);
check('waivedLegs empty → []', $TR::waivedLegs(['waived_legs'=>'']), []);
check('waivedLegs missing key → []', $TR::waivedLegs([]), []);
$tw = null; foreach (TripRepo::all() as $tt) if ($tt['trip_number']==='99751') $tw = $tt;
check('toggle adds a waived leg', $TR::toggleWaivedLeg((int)$tw['id'], 'RJTT'), ['RJTT']);
check('  → persisted on the row', $TR::waivedLegs(TripRepo::findById((int)$tw['id'])), ['RJTT']);
check('toggle again removes it', $TR::toggleWaivedLeg((int)$tw['id'], 'RJTT'), []);

// --- 12. Access log: device parsing, geo/IP safety, stats -----------
$mac = AccessLog::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');
check('device: Chrome on macOS desktop', $mac, ['browser'=>'Chrome','os'=>'macOS','kind'=>'Desktop']);
$iph = AccessLog::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
check('device: Safari on iOS mobile', $iph, ['browser'=>'Safari','os'=>'iOS','kind'=>'Mobile']);
$win = AccessLog::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0');
check('device: Firefox on Windows', $win['browser'].'/'.$win['os'], 'Firefox/Windows');
$edge = AccessLog::device('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 Edg/126.0');
check('device: Edge wins over Chrome token', $edge['browser'], 'Edge');
check('device: empty UA → Unknown/Unknown', AccessLog::device('')['browser'], 'Unknown');

// Private/invalid IPs never trigger a lookup — always blank.
check('geoip: private IP → blank', AccessLog::geoip('10.0.0.5'), ['city'=>'','country'=>'','isp'=>'']);
check('geoip: empty IP → blank', AccessLog::geoip(''), ['city'=>'','country'=>'','isp'=>'']);

// clientIp prefers the first PUBLIC X-Forwarded-For entry over private hops.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 203.0.113.9, 70.1.2.3';
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
check('clientIp: first public XFF entry', AccessLog::clientIp(), '203.0.113.9');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
check('clientIp: falls back to REMOTE_ADDR', AccessLog::clientIp(), '10.0.0.1');

// Stats aggregate success/failed, distinct accounts + IPs.
Db::insert('access_log', ['email'=>'a@x.com','result'=>'success','ip'=>'1.1.1.1','user_agent'=>'']);
Db::insert('access_log', ['email'=>'a@x.com','result'=>'success','ip'=>'1.1.1.2','user_agent'=>'']);
Db::insert('access_log', ['email'=>'b@x.com','result'=>'failed','ip'=>'1.1.1.1','user_agent'=>'']);
$as = AccessLog::stats();
check('stats: total recorded', $as['total'], 3);
check('stats: distinct accounts', $as['accounts'], 2);
check('stats: distinct IPs', $as['ips'], 2);
check('stats: failed attempts', $as['failed'], 1);
check('stats: 24h success count', $as['day'], 2);

// --- 13. Accounts & roles: who may open the access log --------------
Users::set('super@x.com', 'sUp3rPass!', Users::SUPERADMIN, 'Super');
Users::set('demo@x.com',  'demoPass1',  Users::USER,       'Demo');
check('two accounts created', Users::count(), 2);

// Password verification, both directions.
check('correct password → user row', Users::check('super@x.com', 'sUp3rPass!')['email'] ?? null, 'super@x.com');
check('wrong password → null', Users::check('super@x.com', 'nope'), null);
check('unknown email → null', Users::check('ghost@x.com', 'sUp3rPass!'), null);
check('email match is case-insensitive', Users::check('SUPER@X.com', 'sUp3rPass!')['email'] ?? null, 'super@x.com');
check('password is not stored in plaintext',
    str_contains((string)Users::find('demo@x.com')['password_hash'], 'demoPass1'), false);

// The whole point: only superadmin sees the access log.
check('superadmin may view access log', Users::canViewAccessLog(Users::SUPERADMIN), true);
check('user may NOT view access log', Users::canViewAccessLog(Users::USER), false);
check('unknown role may NOT view access log', Users::canViewAccessLog('wizard'), false);
check('no role at all may NOT view', Users::canViewAccessLog(null), false);
check('unknown role normalises down to user', Users::normalizeRole('wizard'), Users::USER);

// Quick login is a public bypass — it must never hand out a superadmin session.
Settings::set('auth.quick_login_email', 'demo@x.com');
check('quick login targets the demo account', Users::quickLoginUser()['email'], 'demo@x.com');
check('  → and it is allowed', Users::canQuickLogin(Users::quickLoginUser()), true);
Settings::set('auth.quick_login_email', 'super@x.com');
check('quick login REFUSES a superadmin', Users::canQuickLogin(Users::quickLoginUser()), false);
Settings::set('auth.quick_login_email', 'demo@x.com');

// Role changes and the last-superadmin guard.
check('setRole promotes', Users::setRole('demo@x.com', Users::SUPERADMIN), true);
check('  → role persisted', Users::find('demo@x.com')['role'], Users::SUPERADMIN);
check('  → quick login now refuses it', Users::canQuickLogin(Users::find('demo@x.com')), false);
Users::setRole('demo@x.com', Users::USER);
check('setRole on unknown account → false', Users::setRole('ghost@x.com', Users::SUPERADMIN), false);
check('cannot delete the last superadmin', Users::delete('super@x.com'), false);
check('  → it is still there', Users::find('super@x.com') !== null, true);
check('can delete a non-superadmin', Users::delete('demo@x.com'), true);
check('  → and it is gone', Users::find('demo@x.com'), null);

// set() on an existing email updates rather than duplicating.
Users::set('super@x.com', 'newPass99', Users::SUPERADMIN, 'Super');
check('re-set does not duplicate the account', Users::count(), 1);
check('  → new password works', Users::check('super@x.com', 'newPass99')['email'] ?? null, 'super@x.com');
check('  → old password rejected', Users::check('super@x.com', 'sUp3rPass!'), null);

// Legacy single-admin migration: settings pair → users row, once.
Db::q("DELETE FROM users");
Settings::set('auth.email', 'legacy@x.com');
Settings::set('auth.password_hash', password_hash('legacyPass', PASSWORD_DEFAULT));
check('legacy admin migrates in', Users::seedFromLegacy(), 'legacy@x.com');
check('  → as superadmin (it was the only login)', Users::find('legacy@x.com')['role'], Users::SUPERADMIN);
check('  → its password still works', Users::check('legacy@x.com', 'legacyPass')['email'] ?? null, 'legacy@x.com');
check('re-running the migration is a no-op', Users::seedFromLegacy(), null);
check('  → still exactly one account', Users::count(), 1);
// --- 14. tagBill: trip number written into the description "Trip No:" line ---
$XC = '\App\Service\Xero\XeroApiClient';
$lines = [[
  'LineItemID' => 'li-1',
  'Description' => "Landing, Handling & Associated Charges at EINN on 07/07/2026 for N488MH\nTrip No:",
  'Quantity' => 1, 'UnitAmount' => 1012.72, 'AccountCode' => '325', 'TaxType' => 'INPUT',
]];
$tagged = $XC::embedTripInLines($lines, '35184');
check('tag fills the blank Trip No: line', $tagged[0]['Description'],
    "Landing, Handling & Associated Charges at EINN on 07/07/2026 for N488MH\nTrip No: 35184");
check('  → LineItemID preserved', $tagged[0]['LineItemID'], 'li-1');
check('  → amount preserved', $tagged[0]['UnitAmount'], 1012.72);
check('  → Reference not in the payload (untouched)', array_key_exists('Reference', $tagged[0]), false);

$lines2 = [['Description' => "Charges at EINN for N488MH\nTrip No: 00000", 'UnitAmount' => 5.0]];
check('tag replaces an existing Trip No', $XC::embedTripInLines($lines2, '35184')[0]['Description'],
    "Charges at EINN for N488MH\nTrip No: 35184");

$lines3 = [['Description' => 'Charges at EINN for N488MH', 'UnitAmount' => 5.0]];
check('tag appends Trip No when the line has none', $XC::embedTripInLines($lines3, '35184')[0]['Description'],
    "Charges at EINN for N488MH\nTrip No: 35184");

$lines4 = [['Description' => "X\nTrip No:"]];
check('tag keeps alnum/slash trip numbers literal', $XC::embedTripInLines($lines4, '35503/EGJJ')[0]['Description'],
    "X\nTrip No: 35503/EGJJ");

// --- 15. New: Xero status + remarks storage, findByNumber, refresh keys ---
$bx = \App\Repo\BillRepo::upsert('T3', [
    'invoice_id' => 'stat-1', 'supplier' => 'ASA', 'status' => 'paid',
    'description' => 'Handling',
], $M::match(['description'=>'Handling'], $mtrips));
check('upsert stores Xero status (uppercased)', $bx['xero_status'], 'PAID');
check('  → remarks blank until fetched', (string)($bx['remarks'] ?? ''), '');
\App\Repo\BillRepo::setRemarks((int)$bx['id'], 'Called supplier — pay by 15th');
$bx2 = \App\Repo\BillRepo::findById((int)$bx['id']);
check('setRemarks stores the latest note', $bx2['remarks'], 'Called supplier — pay by 15th');
$bx3 = \App\Repo\BillRepo::upsert('T3', ['invoice_id'=>'stat-1','status'=>'authorised'], $M::match(['description'=>'Handling'], $mtrips));
check('re-upsert refreshes Xero status', $bx3['xero_status'], 'AUTHORISED');
check('  → remarks survive re-upsert', $bx3['remarks'], 'Called supplier — pay by 15th');

check('findByNumber resolves a real trip', TripRepo::findByNumber('99751')['trip_number'] ?? '', '99751');
check('findByNumber unknown → null', TripRepo::findByNumber('does-not-exist'), null);
check('findByNumber blank → null', TripRepo::findByNumber('  '), null);

// billHistory: created + latest note extracted from Xero History records.
$hist = (new \App\Service\Xero\XeroStubClient())->billHistory('x');
check('stub billHistory shape', array_keys($hist), ['created','note']);

// --- 16. Inbox: real WazzOCR classifier, link/number extraction ------
$IL = '\App\Service\Inbox\InboxLog';
$succ = "🤖 *AI bill analysis*\nSupplier: Signature Flight Support Ireland Ltd\nInvoice No: Demo12345-SN030473\n"
      . "✅ *Xero draft bill created*\nInvoice: Demo12345-SN030473\nStatus: DRAFT\n"
      . "View: https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=42774757-6a0c-4639-916a-e1a4428bb003";
$exists   = "🤖 *AI bill analysis*\nInvoice No: Demo12345-SN030473\n⚠️ *Already exists in Xero*\nDemo12345-SN030473 is already in Xero — no action needed.";
$failmsg  = "🤖 *AI bill analysis*\nSupplier: X\n❌ Could not create the bill — missing account code";
$progress = "🔍 Reading your image...";
$opener   = "Signum Aviation Attachment from email";
$analysis = "🤖 *AI bill analysis*\nSupplier: Signature Flight Support Ireland Ltd\nInvoice No: SN030488\n*Total: 1,595.50*";
$needorg  = $analysis . "\n\n⚠️ *Action needed: pick the organisation*\nNo matching organisation found.";

check('classify: draft bill created → success', $IL::classify($succ), 'success');
check('classify: already exists → failed (no bill created)', $IL::classify($exists), 'failed');
check('classify: analysis + error → failed', $IL::classify($failmsg), 'failed');
check('classify: analysis only → note', $IL::classify($analysis), 'note');
check('classify: action needed → note', $IL::classify($needorg), 'note');
check('classify: explicit failure words → failed', $IL::classify('Sorry, could not create the bill'), 'failed');
check('classify: progress ping → ignore', $IL::classify($progress), 'ignore');
check('classify: old opener → ignore', $IL::classify($opener), 'ignore');
check('classify: blank → ignore', $IL::classify('   '), 'ignore');

check('extract bill url', $IL::extractBillUrl($succ), 'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=42774757-6a0c-4639-916a-e1a4428bb003');
check('extract bill number', $IL::extractBillNumber($succ), 'Demo12345-SN030473');
check('extract url absent → blank', $IL::extractBillUrl($exists), '');

// A sent delivery starts pending; a progress ping must NOT consume it.
$id1 = $IL::recordDelivery(['event_at'=>'2026-08-13T01:00:00Z','sender'=>'ops@asa.com','attachment'=>'inv1.pdf','delivery'=>'sent']);
$id2 = $IL::recordDelivery(['event_at'=>'2026-08-13T01:01:00Z','sender'=>'ops@asa.com','attachment'=>'inv2.pdf','delivery'=>'sent']);
$pp  = $IL::recordReply('wz-p', $progress, 'Bot', '2026-08-13T01:02:00Z');
check('progress ping ignored', $pp['status'], 'ignore');
check('  → oldest still pending after ping', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$id1])['ocr_status'], 'pending');

// Success result attaches to the oldest pending, storing link + number.
$r1 = $IL::recordReply('wz-1', $succ, 'Bot', '2026-08-13T01:03:00Z');
check('success matched a pending row', $r1['matched'], true);
$row1 = \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$id1]);
check('  → id1 success', $row1['ocr_status'], 'success');
check('  → id1 bill_url stored', $row1['bill_url'], 'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=42774757-6a0c-4639-916a-e1a4428bb003');
check('  → id1 bill_number stored', $row1['bill_number'], 'Demo12345-SN030473');
check('  → id2 still pending', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$id2])['ocr_status'], 'pending');

// Duplicate webhook delivery (same message id) is ignored.
check('duplicate reply ignored', $IL::recordReply('wz-1', $succ, 'Bot', '2026-08-13T01:03:00Z')['status'], 'duplicate');

// A failed send never becomes pending, so it can't absorb a reply.
$idF = $IL::recordDelivery(['event_at'=>'2026-08-13T01:04:00Z','attachment'=>'bad.pdf','delivery'=>'failed','delivery_error'=>'File-drop HTTP 500']);
check('failed send is not pending', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$idF])['ocr_status'], '');

// A failure result consumes id2's pending slot.
check('failed result classified', $IL::recordReply('wz-2', $failmsg, 'Bot', '2026-08-13T01:05:00Z')['status'], 'failed');
check('  → id2 failed', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$id2])['ocr_status'], 'failed');

// Nothing pending now → an extra result is DROPPED, never a standalone row.
$before = (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events");
$r3 = $IL::recordReply('wz-3', $exists, 'Bot', '2026-08-13T01:06:00Z');
check('no pending → result dropped', $r3['matched'], false);
check('  → no standalone row added', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events"), $before);

// rows() only ever returns invoice-email rows (never processor-only rows).
check('rows() = the 3 deliveries', count($IL::rows()), 3);
$ist = $IL::stats();
check('stats: bills created (success)', $ist['created'], 1);
check('stats: errors (failed result + failed send)', $ist['errors'], 2);
check('stats: awaiting result', $ist['pending'], 0);

// --- 17. Inbox: two-message replies + the match window ----------------
// The processor sends the analysis first, then the create confirmation. The
// analysis must NOT close the row, or the confirmation lands on the next
// attachment and every later row is shifted by one (the SN030473 bug).
$now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$iso  = fn(int $mins) => $now->modify("$mins minutes")->format('Y-m-d\TH:i:s\Z');
$aId  = $IL::recordDelivery(['event_at'=>$iso(-8),'attachment'=>'a.pdf','delivery'=>'sent']);
$bId  = $IL::recordDelivery(['event_at'=>$iso(-6),'attachment'=>'b.pdf','delivery'=>'sent']);

$n1 = $IL::recordReply('wz-n1', $needorg, 'Bot', $iso(-5));
check('note matched the oldest pending', $n1['status'], 'note');
$rowA = \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$aId]);
check('  → row stays pending after a note', $rowA['ocr_status'], 'pending');
check('  → note text kept', strpos((string)$rowA['ocr_message'], 'Action needed') !== false, true);
check('  → next attachment untouched', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$bId])['ocr_status'], 'pending');

$c1 = $IL::recordReply('wz-c1', $succ, 'Bot', $iso(-4));
check('confirmation closes the SAME row', $c1['status'], 'success');
$rowA = \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$aId]);
check('  → a.pdf success', $rowA['ocr_status'], 'success');
check('  → a.pdf got the bill link', $rowA['bill_url'], 'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=42774757-6a0c-4639-916a-e1a4428bb003');
check('  → whole thread kept for hover', strpos((string)$rowA['ocr_message'], 'Action needed') !== false
                                       && strpos((string)$rowA['ocr_message'], 'draft bill created') !== false, true);
check('  → b.pdf still waiting', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$bId])['ocr_status'], 'pending');

// A delivery older than the match window can no longer absorb a reply — that is
// what let one unanswered attachment shift every later result onto the wrong row.
$oldId = $IL::recordDelivery(['event_at'=>$iso(-60 * (\App\Service\Inbox\InboxLog::MATCH_WINDOW_HOURS + 2)),'attachment'=>'stale.pdf','delivery'=>'sent']);
\App\Db::q("UPDATE inbox_events SET ocr_status='success' WHERE id=?", [$bId]);   // only the stale row is pending
$r4 = $IL::recordReply('wz-4', $succ, 'Bot', $iso(-1));
check('stale pending ignored → reply dropped', $r4['matched'], false);
check('  → stale row untouched', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$oldId])['ocr_status'], 'pending');
check('stale pending counts as an error, not as waiting', $IL::stats()['pending'], 0);
check('  → and shows up in errors', $IL::stats()['errors'] >= 3, true);
check('isStale: old delivery', $IL::isStale($now->modify('-9 hours')->format('Y-m-d H:i:s')), true);
check('isStale: recent delivery', $IL::isStale($now->modify('-5 minutes')->format('Y-m-d H:i:s')), false);
// Silent vs answered: a delivery the processor never acknowledged dies in minutes,
// one it is mid-conversation with keeps the long window.
check('isStale: silent for 20 min', $IL::isStale($now->modify('-20 minutes')->format('Y-m-d H:i:s')), true);
check('isStale: answered 20 min ago', $IL::isStale($now->modify('-20 minutes')->format('Y-m-d H:i:s'), 'AI bill analysis…'), false);

// The 11:02/11:16 bug: an earlier send that got NO reply must not absorb the result
// belonging to the attachment sent moments before the reply arrived.
$silent = $IL::recordDelivery(['event_at'=>$iso(-25),'attachment'=>'silent.pdf','delivery'=>'sent']);
$fresh  = $IL::recordDelivery(['event_at'=>$iso(-1),'attachment'=>'fresh.pdf','delivery'=>'sent']);
$r6 = $IL::recordReply('wz-6', $succ, 'Bot', $now->format('Y-m-d\TH:i:s\Z'));
check('reply skips the silent delivery', $r6['matched'], true);
check('  → fresh.pdf got the bill', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$fresh])['ocr_status'], 'success');
check('  → silent.pdf left alone', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$silent])['ocr_status'], 'pending');

// "Already exists in Xero" made no bill from this send: it closes the row as a
// failure and keeps the processor's wording for the Message column.
$dupId = $IL::recordDelivery(['event_at'=>$iso(-3),'attachment'=>'dup.pdf','delivery'=>'sent']);
$r5    = $IL::recordReply('wz-5', $exists, 'Bot', $iso(-2));
check('already-exists reply → failed', $r5['status'], 'failed');
$rowD = \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$dupId]);
check('  → row marked failed', $rowD['ocr_status'], 'failed');
check('  → WhatsApp wording kept', strpos((string)$rowD['ocr_message'], 'is already in Xero') !== false, true);
check('  → no bill link claimed', $rowD['bill_url'], '');

// --- 18. Inbox: plain-English messages + clearing a duplicate --------
// The processor's WhatsApp blocks are long; the Message column must say what
// happened in one sentence an operator can act on.
$DB = '\App\Service\Inbox\DuplicateBill';
check('isDuplicate: already exists', $IL::isDuplicate($exists), true);
check('isDuplicate: plain failure', $IL::isDuplicate($failmsg), false);

check('plain: duplicate names the bill', $IL::plainMessage($rowD),
      'Already in Xero — bill Demo12345-SN030473 exists, so no new bill was made.');
check('plain: created bill says nothing', $IL::plainMessage($rowA), '');
check('plain: pending says nothing', $IL::plainMessage(['ocr_status'=>'pending','ocr_message'=>$analysis]), '');
check('plain: send error passed through', $IL::plainMessage(\App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$idF])),
      'File-drop HTTP 500');
check('plain: other failure → processor headline',
      $IL::plainMessage(['ocr_status'=>'failed','ocr_message'=>$failmsg]),
      'Could not create the bill — missing account code');
check('plain: unreadable file → fixed wording',
      $IL::plainMessage(['ocr_status'=>'failed','ocr_message'=>"❌ *Unsupported file* — could not read it"]),
      'The invoice could not be read — send a clearer PDF or photo.');

// The Inbox clears an "already in Xero" duplicate by itself: delete the leftover
// DRAFT bill, then re-send the same file so the bill is created fresh.
check('duplicate is pending clearing', $DB::isPending($rowD), true);
check('  → knows the bill number', $DB::numberFor($rowD), 'Demo12345-SN030473');
check('not pending on a plain failure', $DB::isPending(\App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$id2])), false);
check('not pending on a created bill', $DB::isPending($rowA), false);

// Fake Xero orgs, so the tests never touch a real one.
$fakeXero = function (array $found, bool $delOk = true) {
    return new class($found, $delOk) extends \App\Service\Xero\XeroStubClient {
        public $deleted = [];
        private $found; private $delOk;
        public function __construct(array $found, bool $delOk) { $this->found = $found; $this->delOk = $delOk; }
        public function findBillByNumber(string $n): array { return $this->found + ['invoice_number' => $n]; }
        public function deleteDraftBill(string $id): array {
            $this->deleted[] = $id;
            return $this->delOk ? ['ok' => true, 'status' => 'DELETED', 'stubbed' => false]
                                : ['ok' => false, 'status' => 'DRAFT', 'stubbed' => false, 'error' => 'Xero said no'];
        }
    };
};
$DRAFT = ['ok'=>true,'found'=>true,'invoice_id'=>'inv-1','status'=>'DRAFT','supplier'=>'S','total'=>1.0,'currency'=>'EUR'];
$reload = fn(int $id) => \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$id]);
$dupRow = fn(string $token = '') => (function () use ($IL, $exists, $token) {
    $id = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'sender'=>'ops@asa.com','subject'=>'Invoice',
                               'attachment'=>'dup.pdf','att_size'=>1234,'delivery'=>'sent','drop_token'=>$token]);
    \App\Db::q("UPDATE inbox_events SET ocr_status='failed', ocr_message=?, bill_number='Demo12345-SN030473' WHERE id=?", [$exists, $id]);
    return $id;
})();

// The file is still in the drop, so the whole cycle runs: delete + re-send.
$dropDir = sys_get_temp_dir() . '/skyledger-drop-' . getmypid();
@mkdir($dropDir, 0770, true);
$GLOBALS['config']['drop']['dir'] = $dropDir;
register_shutdown_function(function () use ($dropDir) {
    foreach (glob($dropDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dropDir);
});
// Sending must never leave the test run, so the relay is deliberately unconfigured.
$GLOBALS['config']['wazzup'] = ['api_key' => '', 'channel_id' => '', 'wazzocr_number' => ''];
check('relay off in tests', \App\Service\Inbox\Wazzup::isConfigured(), false);

$token = 'a1b2c3d4e5f60718293a4b5c6d7e8f90.pdf';
file_put_contents($dropDir . '/' . $token, str_repeat('x', 1234));
check('DropStore finds the file', \App\Service\Inbox\DropStore::has($token), true);
check('DropStore token from url', \App\Service\Inbox\DropStore::tokenFromUrl('https://x.test/drop/' . $token), $token);
check('DropStore public url', \App\Service\Inbox\DropStore::url($token, 'https://x.test'), 'https://x.test/drop/' . $token);

$autoId = $dupRow($token);
$before = (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events");
$x  = $fakeXero($DRAFT);
$rs = $DB::autoResolve($autoId, $x);
check('draft duplicate is deleted in Xero', $x->deleted, ['inv-1']);
check('  → a re-send row is logged', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events"), $before + 1);
$retry = \App\Db::one("SELECT * FROM inbox_events WHERE retry_of = ?", [$autoId]);
check('  → retry carries the same file', $retry['drop_token'], $token);
check('  → retry reuses the invoice details', [$retry['attachment'], (int)$retry['att_size']], ['dup.pdf', 1234]);
// The relay is off in tests, so the send fails and the retry row says so — the
// row still exists, which is what stops a fast reply landing on nothing.
check('  → failed send marked on the retry row', $retry['delivery'], 'failed');
check('  → and it is not left waiting', $retry['ocr_status'], '');
check('  → original says what was done', strpos((string)$reload($autoId)['dup_action'], 'Deleted bill Demo12345-SN030473 in Xero') === 0, true);
// The send failed here, so it is NOT presented as handled: the row stays an
// error and says what is wrong.
check('  → half-done is not a success', $DB::wasCleared($reload($autoId)), false);
check('  → and still explains the problem', strpos($IL::plainMessage($reload($autoId)), 'Already in Xero — bill'), 0);
check('  → and is not tried twice', $DB::isPending($reload($autoId)), false);
check('  → repeat call is a no-op', $DB::autoResolve($autoId, $x)['ok'], false);
check('  → no second delete', $x->deleted, ['inv-1']);

// An approved bill is somebody's real work: never deleted, and nothing re-sent.
$authId = $dupRow($token);
$x2 = $fakeXero(['ok'=>true,'found'=>true,'invoice_id'=>'inv-2','status'=>'AUTHORISED','supplier'=>'S','total'=>1.0,'currency'=>'EUR']);
$DB::autoResolve($authId, $x2);
check('authorised duplicate left in Xero', $x2->deleted, []);
check('  → still an error, not a success', $DB::wasCleared($reload($authId)), false);
check('  → row explains why', strpos((string)$reload($authId)['dup_action'], 'is authorised in Xero, not a draft') !== false, true);
check('  → nothing re-sent', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE retry_of = ?", [$authId]), 0);

// A retry that hits the same bill again stops there — no delete/re-send loop.
$loopId = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'attachment'=>'dup.pdf','att_size'=>1234,
                               'delivery'=>'sent','drop_token'=>$token,'retry_of'=>$autoId]);
\App\Db::q("UPDATE inbox_events SET ocr_status='failed', ocr_message=?, bill_number='Demo12345-SN030473' WHERE id=?", [$exists, $loopId]);
$x3 = $fakeXero($DRAFT);
check('retry duplicate is not cleared again', $DB::autoResolve($loopId, $x3)['ok'], false);
check('  → nothing deleted', $x3->deleted, []);
check('  → row says it needs a look', strpos((string)$reload($loopId)['dup_action'], 'Still already in Xero after re-sending'), 0);

// Gone from Xero already: nothing to delete, but the invoice is still re-sent.
$goneId = $dupRow($token);
$x4 = $fakeXero(['ok'=>true,'found'=>false,'invoice_id'=>'','status'=>'','supplier'=>'','total'=>null,'currency'=>'']);
$DB::autoResolve($goneId, $x4);
check('missing bill → still re-sent', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE retry_of = ?", [$goneId]), 1);
check('  → row says it was already gone', strpos((string)$reload($goneId)['dup_action'], 'was already gone from Xero') !== false, true);

// The whole cycle working: old copy deleted, invoice on its way through the
// processor again — the Inbox shows that as a success, not an error. The relay is
// stubbed here so the send is exercised without leaving the machine.
$GLOBALS['config']['wazzup'] = ['api_key' => 'k', 'channel_id' => 'c', 'wazzocr_number' => '600000'];
$sent = [];
\App\Service\Inbox\Wazzup::$transport = function (array $payload) use (&$sent) { $sent[] = $payload; return ['ok' => true]; };
$okId = $dupRow($token);
$errBefore = $IL::stats()['errors'];
$x6 = $fakeXero($DRAFT);
check('full cycle reports success', $DB::autoResolve($okId, $x6)['ok'], true);
check('  → old copy deleted', $x6->deleted, ['inv-1']);
check('  → file re-sent to the processor', $sent[0]['contentUri'] ?? '', \App\Service\Inbox\DropStore::url($token));
check('  → to the processor number', $sent[0]['chatId'] ?? '', '600000');
$okRetry = \App\Db::one("SELECT * FROM inbox_events WHERE retry_of = ?", [$okId]);
check('  → re-send is waiting for its result', $okRetry['ocr_status'], 'pending');
check('  → row is flagged cleared', $DB::wasCleared($reload($okId)), true);
check('  → message is the short one', $IL::plainMessage($reload($okId)), 'Duplicate invoice detected, auto deleted old copy.');
// Operators read Malaysia time everywhere in the Inbox; only stored fields are UTC.
$okNote = (string)$reload($okId)['dup_action'];
check('  → note is stamped in MYT', substr($okNote, -4), ' MYT');
check('  → with today\'s local date', strpos($okNote, date('d M Y')) !== false, true);
check('  → and it drops out of the Errors tile', $IL::stats()['errors'], $errBefore - 1);
\App\Service\Inbox\Wazzup::$transport = null;
$GLOBALS['config']['wazzup'] = ['api_key' => '', 'channel_id' => '', 'wazzocr_number' => ''];

// The file has expired out of the drop: say so instead of re-sending nothing.
$noFileId = $dupRow('');
$DB::autoResolve($noFileId, $fakeXero($DRAFT));
check('no file → nothing re-sent', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE retry_of = ?", [$noFileId]), 0);
check('  → row explains', strpos((string)$reload($noFileId)['dup_action'], 'could not re-send the invoice') > 0, true);

// A Xero outage must not look like a cleared duplicate.
$errId = $dupRow($token);
$DB::autoResolve($errId, $fakeXero(['ok'=>false,'found'=>false,'invoice_id'=>'','status'=>'','supplier'=>'','total'=>null,'currency'=>'','error'=>'Xero is down']));
check('lookup failure is reported', strpos((string)$reload($errId)['dup_action'], 'Could not look up bill'), 0);
check('  → nothing re-sent', (int)\App\Db::scalar("SELECT COUNT(*) FROM inbox_events WHERE retry_of = ?", [$errId]), 0);

// The kill-switch leaves duplicates entirely alone.
$offId = $dupRow($token);
Settings::set('inbox.auto_clear_duplicates', '0');
check('switch off → disabled', $DB::enabled(), false);
$x5 = $fakeXero($DRAFT);
$DB::autoResolve($offId, $x5);
check('  → nothing deleted', $x5->deleted, []);
check('  → row says it is off', strpos((string)$reload($offId)['dup_action'], 'Automatic clearing is off'), 0);
Settings::set('inbox.auto_clear_duplicates', '1');

// A reply tells the caller which row to clear — that is what fires the recovery.
// Clear the decks first: replies go to the OLDEST pending send, and the re-sends
// above are still waiting for theirs.
\App\Db::q("UPDATE inbox_events SET ocr_status='success' WHERE ocr_status='pending'");
$hookId = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'attachment'=>'hook.pdf','att_size'=>10,'delivery'=>'sent']);
$hook   = $IL::recordReply('wz-dup-hook', $exists, 'Bot', gmdate('Y-m-d\TH:i:s\Z'));
check('duplicate reply flags itself', $hook['duplicate'], true);
check('  → and names its row', $hook['row_id'], $hookId);
check('success reply is not a duplicate', $IL::recordReply('wz-ok-hook', $succ, 'Bot', gmdate('Y-m-d\TH:i:s\Z'))['duplicate'] ?? false, false);

// An older Apps Script posts no drop_url: match the upload by type + size instead.
$claimTok = 'b1b2c3d4e5f60718293a4b5c6d7e8f91.pdf';
file_put_contents($dropDir . '/' . $claimTok, str_repeat('y', 4321));
$claimId = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'attachment'=>'claimed.pdf','att_size'=>4321,'delivery'=>'sent']);
check('drop file matched by size', $reload($claimId)['drop_token'], $claimTok);
$claimId2 = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'attachment'=>'claimed2.pdf','att_size'=>4321,'delivery'=>'sent']);
check('  → never claimed twice', (string)$reload($claimId2)['drop_token'], '');
$urlId = $IL::recordDelivery(['event_at'=>gmdate('Y-m-d\TH:i:s\Z'),'attachment'=>'url.pdf','att_size'=>1,'delivery'=>'sent',
                              'drop_url'=>'https://x.test/drop/' . $token]);
check('drop_url wins when given', $reload($urlId)['drop_token'], $token);

// Stub client shapes (Xero disconnected) — never throws, always explains.
$stub = new \App\Service\Xero\XeroStubClient();
check('stub findBillByNumber not found', $stub->findBillByNumber('SN1')['found'], false);
check('stub deleteDraftBill fails', $stub->deleteDraftBill('x')['ok'], false);

// --- 17. Inbox: synchronous WazzOCR External-API result recording ----
$IL = '\App\Service\Inbox\InboxLog';
check('mapWazzStatus created → success',   $IL::mapWazzStatus('created'), 'success');
check('mapWazzStatus duplicate → success', $IL::mapWazzStatus('duplicate'), 'success');
check('mapWazzStatus pending → failed',    $IL::mapWazzStatus('pending'), 'failed');
check('mapWazzStatus error → failed',      $IL::mapWazzStatus('error'), 'failed');
check('mapWazzStatus empty status → pending', $IL::mapWazzStatus('  '), 'pending');

// A delivery carrying a result is recorded final in one shot (no pending, no webhook).
$apiId = $IL::recordDelivery([
    'event_at'=>'2026-08-17T02:00:00Z','sender'=>'ops@sig.com','attachment'=>'inv.pdf','delivery'=>'sent',
    'result'=>'created','ocr_message'=>'1 bill(s) processed successfully.',
    'bill_url'=>'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=abc','bill_number'=>'INV-00421',
]);
$apiRow = \App\Db::one("SELECT * FROM inbox_events WHERE id=?", [$apiId]);
check('API result recorded as success (not pending)', $apiRow['ocr_status'], 'success');
check('  → bill link stored', $apiRow['bill_url'], 'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=abc');
check('  → bill number stored', $apiRow['bill_number'], 'INV-00421');

$dupId = $IL::recordDelivery(['event_at'=>'2026-08-17T02:01:00Z','attachment'=>'dup.pdf','delivery'=>'sent',
    'result'=>'error','ocr_message'=>'Extraction failed']);
check('API error result → failed', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$dupId])['ocr_status'], 'failed');

// No result field (WhatsApp path) still starts pending, unchanged.
$waId = $IL::recordDelivery(['event_at'=>'2026-08-17T02:02:00Z','attachment'=>'wa.pdf','delivery'=>'sent']);
check('no result → still pending (WhatsApp path intact)', \App\Db::one("SELECT ocr_status FROM inbox_events WHERE id=?", [$waId])['ocr_status'], 'pending');

echo "\n" . str_repeat('=', 40) . "\n";
printf("TOTAL: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
