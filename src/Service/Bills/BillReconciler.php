<?php
declare(strict_types=1);

namespace App\Service\Bills;

use App\Repo\BillRepo;
use App\Repo\TripRepo;
use App\Repo\InvoiceRepo;
use App\Service\Invoices\InvoiceService;
use App\Service\Invoices\CompletenessChecker;
use App\Service\Xero\XeroClientFactory;
use App\Service\Xero\XeroOAuth;

/**
 * Module 3 orchestration:
 *   refresh()  pull ACTIVE bills from Xero → match each to a trip → store, and
 *              retire bills/invoices voided or deleted in Xero.
 *   tag()      write the matched trip number into the bill's Xero Reference.
 *   approve()  approve the bill in Xero and, once every bill on the trip is
 *              approved and all legs are costed, raise the client invoice.
 *
 * Reads/writes whatever org Signum Unidash is connected to — which must be the
 * same org the bills are created in.
 */
final class BillReconciler
{
    /** Max bill-History lookups per refresh (Xero allows 60 calls/min). One call
     *  per bill returns both its created-in-Xero time and its latest note. */
    private const HISTORY_LOOKUPS_PER_REFRESH = 40;

    /** @return array{ok:bool, summary?:array, error?:string} */
    public static function refresh(): array
    {
        if (!XeroOAuth::isConnected()) return ['ok' => false, 'error' => 'Connect Xero first (Settings).'];
        $tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');
        $client   = XeroClientFactory::make();

        $res = $client->listActiveBills();
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string)($res['error'] ?? 'Could not read bills from Xero.')];

        // Retire local bills no longer active in Xero (voided/deleted) so they stop
        // showing and stop counting toward costing/recharge.
        $keep = array_values(array_filter(array_map(fn($b) => (string)($b['invoice_id'] ?? ''), $res['bills'])));
        $retired = BillRepo::retireMissing($tenantId, $keep);

        $trips = TripRepo::all();
        $sum = ['pulled' => 0, 'matched' => 0, 'ambiguous' => 0, 'review' => 0, 'tagged' => 0, 'approved' => 0, 'auto_tagged' => 0, 'retired' => $retired];
        // One History call per bill gives both its created-in-Xero time (stored
        // once) and its latest note (refreshed every pull). Capped so a large sync
        // can't burn through Xero's 60-calls-a-minute budget — the remainder is
        // picked up by the next refresh.
        $historyLookups = 0;
        foreach ($res['bills'] as $bill) {
            if (($bill['invoice_id'] ?? '') === '') continue;
            $match = BillMatcher::match($bill, $trips);
            $row = BillRepo::upsert($tenantId, $bill, $match);

            if ($historyLookups < self::HISTORY_LOOKUPS_PER_REFRESH) {
                $historyLookups++;
                $hist = $client->billHistory((string)$bill['invoice_id']);
                if (($row['xero_created_at'] ?? '') === '' && $hist['created'] !== '') {
                    BillRepo::setXeroCreatedAt((int)$row['id'], $hist['created']);
                    $row['xero_created_at'] = $hist['created'];
                }
                if ((string)($row['remarks'] ?? '') !== $hist['note']) {
                    BillRepo::setRemarks((int)$row['id'], $hist['note']);
                    $row['remarks'] = $hist['note'];
                }
            }

            // Auto-tag: a bill matched to exactly one trip has its trip number
            // pushed into Xero straight away — no one has to press Tag.
            if ((string)$row['match_status'] === 'matched' && !empty(self::tag((int)$row['id'])['ok'])) {
                $row = BillRepo::findById((int)$row['id']);
                $sum['auto_tagged']++;
            }

            // Reflect Xero-side approval: an AUTHORISED/PAID bill linked to a trip
            // is approved (whether it was approved here or directly in Xero).
            if (in_array((string)($bill['status'] ?? ''), ['AUTHORISED', 'PAID'], true)
                && $row['matched_trip_id'] !== null && (string)$row['match_status'] !== 'approved') {
                BillRepo::markApproved((int)$row['id']);
                $row = BillRepo::findById((int)$row['id']);
            }
            $sum['pulled']++;
            $st = (string)$row['match_status'];
            if (isset($sum[$st])) $sum[$st]++;
        }

        // Retire client invoices voided/deleted in Xero (trip returns to un-invoiced).
        $sum['invoices_retired'] = self::retireVoidedInvoices($tenantId, $client);

        return ['ok' => true, 'summary' => $sum];
    }

    /**
     * Approve every bill on a trip: tag each (if needed) and authorise it in Xero,
     * then raise the client invoice once all are approved and all legs are costed.
     * @return array{ok:bool, approved:int, failed:int, error?:string, invoiced?:bool, invoice_number?:string, reason?:string}
     */
    public static function approveTrip(int $tripId, bool $force = false): array
    {
        if (!XeroOAuth::isConnected()) return ['ok' => false, 'approved' => 0, 'failed' => 0, 'error' => 'Connect Xero first.'];
        $tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');
        if (!TripRepo::findById($tripId)) return ['ok' => false, 'approved' => 0, 'failed' => 0, 'error' => 'Trip not found.'];

        $bills = BillRepo::forTrip($tenantId, $tripId);
        if (!$bills) return ['ok' => false, 'approved' => 0, 'failed' => 0, 'error' => 'This trip has no matched bills to approve.'];

        $client = XeroClientFactory::make();
        $approved = 0; $failed = 0; $errors = [];
        foreach ($bills as $b) {
            if ((string)$b['match_status'] === 'approved') { $approved++; continue; }
            $label = (string)($b['invoice_number'] ?: 'bill');

            // Ensure the trip number is on the bill's Reference before approving.
            if ((string)$b['match_status'] !== 'tagged') {
                $tag = $client->tagBill((string)$b['xero_invoice_id'], (string)$b['matched_trip_number']);
                if (empty($tag['ok'])) { BillRepo::markError((int)$b['id'], (string)($tag['error'] ?? 'Tag failed.')); $failed++; $errors[] = "{$label}: " . ($tag['error'] ?? 'tag failed'); continue; }
                BillRepo::markTagged((int)$b['id']);
            }

            $ap = $client->approveBill((string)$b['xero_invoice_id']);
            if (empty($ap['ok'])) { BillRepo::markError((int)$b['id'], (string)($ap['error'] ?? 'Approve failed.')); $failed++; $errors[] = "{$label}: " . ($ap['error'] ?? 'approve failed'); continue; }
            BillRepo::markApproved((int)$b['id']);
            $approved++;
        }

        $out = ['ok' => $failed === 0, 'approved' => $approved, 'failed' => $failed];
        if ($errors) $out['error'] = implode('; ', $errors);
        // Only invoice if nothing failed (i.e. every bill is now approved).
        if ($failed === 0) $out += self::maybeInvoiceTrip($tenantId, $tripId, $force);
        return $out;
    }

    /**
     * Raise the client invoice for a trip iff every linked bill is approved and
     * every route leg is costed, and it isn't already invoiced.
     * @return array{invoiced?:bool, invoice_number?:string, reason?:string}
     */
    private static function maybeInvoiceTrip(string $tenantId, int $tripId, bool $force = false): array
    {
        if (InvoiceRepo::findByTrip($tenantId, $tripId)) return [];
        $trip = TripRepo::findById($tripId);
        if (!$trip) return [];
        $bills = BillRepo::forTrip($tenantId, $tripId);
        if (!$bills) return [];
        foreach ($bills as $b) {
            if ((string)$b['match_status'] !== 'approved') return ['invoiced' => false, 'reason' => 'waiting for the trip’s other bills to be approved'];
        }
        // The completeness gate is skipped when the user explicitly forces an
        // invoice for a partial trip ("invoice anyway").
        if (!$force && (CompletenessChecker::check($trip, $bills)['status'] ?? '') !== 'complete') {
            return ['invoiced' => false, 'reason' => 'some route legs still have no bill'];
        }
        $r = InvoiceService::createForTrip($tripId);
        return !empty($r['ok'])
            ? ['invoiced' => true, 'invoice_number' => (string)($r['invoice_number'] ?? '')]
            : ['invoiced' => false, 'reason' => (string)($r['error'] ?? 'invoice failed')];
    }

    /** Remove client invoices that Xero now reports as VOIDED/DELETED. @return int removed. */
    private static function retireVoidedInvoices(string $tenantId, $client): int
    {
        $gone = 0;
        foreach (InvoiceRepo::allForTenant($tenantId) as $inv) {
            $st = $client->invoiceStatus((string)$inv['xero_invoice_id']);
            if (in_array($st, ['VOIDED', 'DELETED'], true)) {
                InvoiceRepo::deleteByTrip($tenantId, (int)$inv['trip_id']);
                $gone++;
            }
        }
        return $gone;
    }

    /** Tag one stored bill (by local id). @return array{ok:bool, error?:string} */
    public static function tag(int $id): array
    {
        $row = BillRepo::findById($id);
        if (!$row) return ['ok' => false, 'error' => 'Bill not found.'];
        if (($row['match_status'] ?? '') === 'tagged') return ['ok' => true];
        if (empty($row['matched_trip_number'])) return ['ok' => false, 'error' => 'No matched trip to tag.'];
        if (!XeroOAuth::isConnected()) return ['ok' => false, 'error' => 'Connect Xero first.'];

        $client = XeroClientFactory::make();
        $res = $client->tagBill((string)$row['xero_invoice_id'], (string)$row['matched_trip_number']);
        if (!empty($res['ok'])) { BillRepo::markTagged((int)$row['id']); return ['ok' => true]; }

        $err = (string)($res['error'] ?? 'Tagging failed.');
        BillRepo::markError((int)$row['id'], $err);
        return ['ok' => false, 'error' => $err];
    }

    /**
     * Manually key a review/ambiguous bill to a trip and push the trip number
     * into Xero (tag) in the same step. @return array{ok:bool, error?:string}
     */
    public static function assign(int $billId, int $tripId): array
    {
        if (!BillRepo::findById($billId)) return ['ok' => false, 'error' => 'Bill not found.'];
        $trip = TripRepo::findById($tripId);
        if (!$trip) return ['ok' => false, 'error' => 'Trip not found.'];
        BillRepo::setMatch($billId, $trip);
        return self::tag($billId);
    }

    /** Tag every matched-but-untagged bill. @return array{tagged:int, failed:int} */
    public static function tagAllMatched(): array
    {
        $tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');
        $tagged = 0; $failed = 0;
        foreach (BillRepo::matchedUntagged($tenantId) as $row) {
            self::tag((int)$row['id'])['ok'] ? $tagged++ : $failed++;
        }
        return ['tagged' => $tagged, 'failed' => $failed];
    }
}
