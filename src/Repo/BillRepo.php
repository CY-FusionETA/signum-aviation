<?php
declare(strict_types=1);

namespace App\Repo;

use App\Db;

/** Persistence for reconciled Xero bills. Keyed by (tenant_id, xero_invoice_id). */
final class BillRepo
{
    public static function find(string $tenantId, string $invoiceId): ?array
    {
        return Db::one("SELECT * FROM xero_bills WHERE tenant_id = ? AND xero_invoice_id = ?", [$tenantId, $invoiceId]);
    }

    public static function findById(int $id): ?array
    {
        return Db::one("SELECT * FROM xero_bills WHERE id = ?", [$id]);
    }

    /**
     * Insert/update a pulled bill + its match. Never clobbers a bill already
     * tagged (keeps the tagged state + timestamp).
     */
    public static function upsert(string $tenantId, array $bill, array $match): array
    {
        $existing = self::find($tenantId, (string)$bill['invoice_id']);
        $trip = $match['trip'] ?? null;
        // Never downgrade a bill we've already tagged or approved back to a raw match.
        $sticky = ['tagged', 'approved'];
        $status = $existing && in_array((string)($existing['match_status'] ?? ''), $sticky, true)
            ? (string)$existing['match_status'] : $match['status'];

        // A re-match that finds no trip must not wipe a link we already have.
        // The reconcile cron re-upserts every bill every 15 min, so blanking
        // here would silently drop manually-assigned and tagged bills out of
        // Module 5 (tagged() requires matched_trip_id IS NOT NULL).
        if (!$trip && $existing && $existing['matched_trip_id'] !== null) {
            $trip = [
                'id'          => $existing['matched_trip_id'],
                'trip_number' => $existing['matched_trip_number'],
                'client_name' => $existing['matched_client'],
            ];
            if ($status !== 'tagged') $status = $existing['match_status'];
        }

        $fields = [
            'tenant_id'           => $tenantId,
            'xero_invoice_id'     => (string)$bill['invoice_id'],
            'invoice_number'      => (string)($bill['invoice_number'] ?? ''),
            'supplier'            => (string)($bill['supplier'] ?? ''),
            'bill_date'           => (string)($bill['bill_date'] ?? ''),
            'reference'           => (string)($bill['reference'] ?? ''),
            'total'               => $bill['total'] ?? null,
            'currency'            => (string)($bill['currency'] ?? ''),
            'currency_rate'       => $bill['currency_rate'] ?? null,
            'base_currency'       => (string)($bill['base_currency'] ?? ''),
            'base_total'          => $bill['base_total'] ?? null,
            'description'         => (string)($bill['description'] ?? ''),
            'ex_airport'          => (string)($match['ex_airport'] ?? ''),
            'ex_date'             => (string)($match['ex_date'] ?? ''),
            'ex_tail'             => (string)($match['ex_tail'] ?? ''),
            'match_status'        => $status,
            'matched_trip_id'     => $trip ? (int)$trip['id'] : null,
            'matched_trip_number' => $trip ? (string)$trip['trip_number'] : null,
            'matched_client'      => $trip ? (string)$trip['client_name'] : null,
        ];

        if ($existing) {
            $set = implode(',', array_map(fn($c) => "{$c}=:{$c}", array_keys($fields)));
            $fields['__id'] = (int)$existing['id'];
            Db::q("UPDATE xero_bills SET {$set}, updated_at=CURRENT_TIMESTAMP WHERE id=:__id", $fields);
            return self::find($tenantId, (string)$bill['invoice_id']);
        }
        Db::insert('xero_bills', $fields);
        return self::find($tenantId, (string)$bill['invoice_id']);
    }

    /** Manually link a bill to a trip (staff override for review/ambiguous). */
    public static function setMatch(int $id, array $trip): void
    {
        Db::q(
            "UPDATE xero_bills
                SET matched_trip_id=?, matched_trip_number=?, matched_client=?, match_status='matched',
                    xero_last_error=NULL, updated_at=CURRENT_TIMESTAMP
              WHERE id=?",
            [(int)$trip['id'], (string)$trip['trip_number'], (string)$trip['client_name'], $id]
        );
    }

    public static function markTagged(int $id): void
    {
        Db::q("UPDATE xero_bills SET match_status='tagged', tagged_at=CURRENT_TIMESTAMP, xero_last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
    }

    public static function markApproved(int $id): void
    {
        Db::q("UPDATE xero_bills SET match_status='approved', xero_last_error=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$id]);
    }

    /** Bills linked to a trip (matched, tagged or approved). */
    public static function forTrip(string $tenantId, int $tripId): array
    {
        return Db::all(
            "SELECT * FROM xero_bills WHERE tenant_id = ? AND matched_trip_id = ?
               AND match_status IN ('matched','tagged','approved')",
            [$tenantId, $tripId]
        );
    }

    /**
     * Retire local bills that are no longer active in Xero (voided/deleted, or
     * otherwise gone from the pulled set). Keeps only the given Xero invoice ids.
     * @return int rows removed.
     */
    public static function retireMissing(string $tenantId, array $keepInvoiceIds): int
    {
        if (!$keepInvoiceIds) {
            return Db::q("DELETE FROM xero_bills WHERE tenant_id = ?", [$tenantId])->rowCount();
        }
        $ph = implode(',', array_fill(0, count($keepInvoiceIds), '?'));
        return Db::q(
            "DELETE FROM xero_bills WHERE tenant_id = ? AND xero_invoice_id NOT IN ({$ph})",
            array_merge([$tenantId], array_values($keepInvoiceIds))
        )->rowCount();
    }

    public static function markError(int $id, string $error): void
    {
        Db::q("UPDATE xero_bills SET xero_last_error=?, updated_at=CURRENT_TIMESTAMP WHERE id=?", [$error, $id]);
    }

    /**
     * Record when the bill was created in Xero (UTC). Written once, on the first
     * refresh that can read it — upsert() never touches the column, so tagging or
     * approving the bill later cannot move the timestamp.
     */
    public static function setXeroCreatedAt(int $id, string $utc): void
    {
        Db::q("UPDATE xero_bills SET xero_created_at=? WHERE id=?", [$utc, $id]);
    }

    public static function allForTenant(string $tenantId): array
    {
        return Db::all("SELECT * FROM xero_bills WHERE tenant_id = ? ORDER BY bill_date DESC, id DESC", [$tenantId]);
    }

    public static function matchedUntagged(string $tenantId): array
    {
        return Db::all("SELECT * FROM xero_bills WHERE tenant_id = ? AND match_status = 'matched'", [$tenantId]);
    }

    /** All tagged bills (linked to a trip) — Module 5 input. */
    public static function tagged(string $tenantId): array
    {
        return Db::all("SELECT * FROM xero_bills WHERE tenant_id = ? AND match_status = 'tagged' AND matched_trip_id IS NOT NULL", [$tenantId]);
    }
}
