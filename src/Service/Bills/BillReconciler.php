<?php
declare(strict_types=1);

namespace App\Service\Bills;

use App\Repo\BillRepo;
use App\Repo\TripRepo;
use App\Service\Xero\XeroClientFactory;
use App\Service\Xero\XeroOAuth;

/**
 * Module 3 orchestration:
 *   refresh()  pull DRAFT bills from Xero → match each to a trip → store.
 *   tag()      write the matched trip number into the bill's Xero Reference,
 *              linking the supplier cost to the trip for later client recharge.
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

        $res = $client->listDraftBills();
        if (empty($res['ok'])) return ['ok' => false, 'error' => (string)($res['error'] ?? 'Could not read bills from Xero.')];

        $trips = TripRepo::all();
        $sum = ['pulled' => 0, 'matched' => 0, 'ambiguous' => 0, 'review' => 0, 'tagged' => 0];
        foreach ($res['bills'] as $bill) {
            if (($bill['invoice_id'] ?? '') === '') continue;
            $match = BillMatcher::match($bill, $trips);
            $row = BillRepo::upsert($tenantId, $bill, $match);
            $sum['pulled']++;
            $st = (string)$row['match_status'];
            if (isset($sum[$st])) $sum[$st]++;
        }
        return ['ok' => true, 'summary' => $sum];
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
