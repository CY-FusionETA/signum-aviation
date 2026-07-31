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
 * same org WazzOCR creates the bills in.
 */
final class BillReconciler
{
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
        $sum = ['pulled' => 0, 'matched' => 0, 'ambiguous' => 0, 'review' => 0, 'tagged' => 0, 'approved' => 0, 'retired' => $retired];
        foreach ($res['bills'] as $bill) {
            if (($bill['invoice_id'] ?? '') === '') continue;
            $match = BillMatcher::match($bill, $trips);
            $row = BillRepo::upsert($tenantId, $bill, $match);
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
     * Approve a bill: tag it (if needed), authorise it in Xero, then raise the
     * client invoice once every bill on its trip is approved and all legs costed.
     * @return array{ok:bool, error?:string, invoiced?:bool, invoice_number?:string, reason?:string}
     */
    public static function approve(int $id): array
    {
        $row = BillRepo::findById($id);
        if (!$row) return ['ok' => false, 'error' => 'Bill not found.'];
        if (empty($row['matched_trip_id']) || empty($row['matched_trip_number'])) {
            return ['ok' => false, 'error' => 'Match this bill to a trip first.'];
        }
        if (!XeroOAuth::isConnected()) return ['ok' => false, 'error' => 'Connect Xero first.'];
        $tenantId = (string)(XeroOAuth::token()['tenant_id'] ?? '');
        $client   = XeroClientFactory::make();

        // Make sure the trip number is on the bill's Reference before approving.
        if (!in_array((string)$row['match_status'], ['tagged', 'approved'], true)) {
            $tag = $client->tagBill((string)$row['xero_invoice_id'], (string)$row['matched_trip_number']);
            if (empty($tag['ok'])) { BillRepo::markError($id, (string)($tag['error'] ?? 'Tag failed.')); return ['ok' => false, 'error' => (string)($tag['error'] ?? 'Tag failed.')]; }
            BillRepo::markTagged($id);
        }

        if ((string)$row['match_status'] !== 'approved') {
            $ap = $client->approveBill((string)$row['xero_invoice_id']);
            if (empty($ap['ok'])) { BillRepo::markError($id, (string)($ap['error'] ?? 'Approve failed.')); return ['ok' => false, 'error' => (string)($ap['error'] ?? 'Approve failed.')]; }
            BillRepo::markApproved($id);
        }

        return ['ok' => true] + self::maybeInvoiceTrip($tenantId, (int)$row['matched_trip_id']);
    }

    /**
     * Raise the client invoice for a trip iff every linked bill is approved and
     * every route leg is costed, and it isn't already invoiced.
     * @return array{invoiced?:bool, invoice_number?:string, reason?:string}
     */
    private static function maybeInvoiceTrip(string $tenantId, int $tripId): array
    {
        if (InvoiceRepo::findByTrip($tenantId, $tripId)) return [];
        $trip = TripRepo::findById($tripId);
        if (!$trip) return [];
        $bills = BillRepo::forTrip($tenantId, $tripId);
        if (!$bills) return [];
        foreach ($bills as $b) {
            if ((string)$b['match_status'] !== 'approved') return ['invoiced' => false, 'reason' => 'waiting for the trip’s other bills to be approved'];
        }
        if ((CompletenessChecker::check($trip, $bills)['status'] ?? '') !== 'complete') {
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

    /** Manually assign a review/ambiguous bill to a trip. @return array{ok:bool, error?:string} */
    public static function assign(int $billId, int $tripId): array
    {
        if (!BillRepo::findById($billId)) return ['ok' => false, 'error' => 'Bill not found.'];
        $trip = TripRepo::findById($tripId);
        if (!$trip) return ['ok' => false, 'error' => 'Trip not found.'];
        BillRepo::setMatch($billId, $trip);
        return ['ok' => true];
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
